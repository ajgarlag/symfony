<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bridge\Doctrine\Validator\Constraints;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\ConstraintDefinitionException;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Unique Entity Validator checks if one or a set of fields contain unique values.
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 */
class UniqueEntityValidator extends ConstraintValidator
{
    private $registry;
    private $propertyAccessor;

    public function __construct(ManagerRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * @param array|object $data
     *
     * @throws UnexpectedTypeException
     * @throws ConstraintDefinitionException
     */
    public function validate($data, Constraint $constraint)
    {
        if (!$constraint instanceof UniqueEntity) {
            throw new UnexpectedTypeException($constraint, UniqueEntity::class);
        }

        if (!\is_array($constraint->fields) && !\is_string($constraint->fields)) {
            throw new UnexpectedTypeException($constraint->fields, 'array');
        }

        if (null !== $constraint->errorPath && !\is_string($constraint->errorPath)) {
            throw new UnexpectedTypeException($constraint->errorPath, 'string or null');
        }

        $fields = (array) $constraint->fields;

        if (0 === \count($fields)) {
            throw new ConstraintDefinitionException('At least one field has to be specified.');
        }

        if (null === $data) {
            return;
        }

        if (!\is_object($data) && null === $constraint->entityClass) {
            throw new ConstraintDefinitionException('To validate non object data, you must define entity class');
        }

        $entityClass = $constraint->entityClass ?? \get_class($data);

        if ($constraint->em) {
            $em = $this->registry->getManager($constraint->em);

            if (!$em) {
                throw new ConstraintDefinitionException(sprintf('Object manager "%s" does not exist.', $constraint->em));
            }
        } else {
            $em = $this->registry->getManagerForClass($entityClass);

            if (!$em) {
                throw new ConstraintDefinitionException(sprintf('Unable to find the object manager associated with an entity of class "%s".', $entityClass));
            }
        }

        $class = $em->getClassMetadata($entityClass);
        $entity = $data instanceof $entityClass ? $data : $this->getEntityToValidate($data, $constraint, $class, $em);

        $criteria = [];
        $hasNullValue = false;

        foreach ($fields as $fieldName) {
            if (!$class->hasField($fieldName) && !$class->hasAssociation($fieldName)) {
                throw new ConstraintDefinitionException(sprintf('The field "%s" is not mapped by Doctrine, so it cannot be validated for uniqueness.', $fieldName));
            }

            $fieldValue = $class->reflFields[$fieldName]->getValue($entity);

            if (null === $fieldValue) {
                $hasNullValue = true;
            }

            if ($constraint->ignoreNull && null === $fieldValue) {
                continue;
            }

            $criteria[$fieldName] = $fieldValue;

            if (null !== $criteria[$fieldName] && $class->hasAssociation($fieldName)) {
                /* Ensure the Proxy is initialized before using reflection to
                 * read its identifiers. This is necessary because the wrapped
                 * getter methods in the Proxy are being bypassed.
                 */
                $em->initializeObject($criteria[$fieldName]);
            }
        }

        // validation doesn't fail if one of the fields is null and if null values should be ignored
        if ($hasNullValue && $constraint->ignoreNull) {
            return;
        }

        // skip validation if there are no criteria (this can happen when the
        // "ignoreNull" option is enabled and fields to be checked are null
        if (empty($criteria)) {
            return;
        }

        if (null !== $constraint->entityClass) {
            /* Retrieve repository from given entity name.
             * We ensure the retrieved repository can handle the entity
             * by checking the entity is the same, or subclass of the supported entity.
             */
            $repository = $em->getRepository($constraint->entityClass);
            $supportedClass = $repository->getClassName();

            if (!$entity instanceof $supportedClass) {
                throw new ConstraintDefinitionException(sprintf('The "%s" entity repository does not support the "%s" entity. The entity should be an instance of or extend "%s".', $constraint->entityClass, $class->getName(), $supportedClass));
            }
        } else {
            $repository = $em->getRepository(\get_class($entity));
        }

        $result = $repository->{$constraint->repositoryMethod}($criteria);

        if ($result instanceof \IteratorAggregate) {
            $result = $result->getIterator();
        }

        /* If the result is a MongoCursor, it must be advanced to the first
         * element. Rewinding should have no ill effect if $result is another
         * iterator implementation.
         */
        if ($result instanceof \Iterator) {
            $result->rewind();
            if ($result instanceof \Countable && 1 < \count($result)) {
                $result = [$result->current(), $result->current()];
            } else {
                $result = $result->current();
                $result = null === $result ? [] : [$result];
            }
        } elseif (\is_array($result)) {
            reset($result);
        } else {
            $result = null === $result ? [] : [$result];
        }

        /* If no entity matched the query criteria or a single entity matched,
         * which is the same as the entity being validated, the criteria is
         * unique.
         */
        if (!$result || (1 === \count($result) && current($result) === $entity)) {
            return;
        }

        $errorPath = null !== $constraint->errorPath ? $constraint->errorPath : $fields[0];
        $invalidValue = isset($criteria[$errorPath]) ? $criteria[$errorPath] : $criteria[current($fields)];

        $this->context->buildViolation($constraint->message)
            ->atPath($errorPath)
            ->setParameter('{{ value }}', $this->formatWithIdentifiers($em, $class, $invalidValue))
            ->setInvalidValue($invalidValue)
            ->setCode(UniqueEntity::NOT_UNIQUE_ERROR)
            ->setCause($result)
            ->addViolation();
    }

    private function formatWithIdentifiers($em, $class, $value)
    {
        if (!\is_object($value) || $value instanceof \DateTimeInterface) {
            return $this->formatValue($value, self::PRETTY_DATE);
        }

        if (method_exists($value, '__toString')) {
            return (string) $value;
        }

        if ($class->getName() !== $idClass = \get_class($value)) {
            // non unique value might be a composite PK that consists of other entity objects
            if ($em->getMetadataFactory()->hasMetadataFor($idClass)) {
                $identifiers = $em->getClassMetadata($idClass)->getIdentifierValues($value);
            } else {
                // this case might happen if the non unique column has a custom doctrine type and its value is an object
                // in which case we cannot get any identifiers for it
                $identifiers = [];
            }
        } else {
            $identifiers = $class->getIdentifierValues($value);
        }

        if (!$identifiers) {
            return sprintf('object("%s")', $idClass);
        }

        array_walk($identifiers, function (&$id, $field) {
            if (!\is_object($id) || $id instanceof \DateTimeInterface) {
                $idAsString = $this->formatValue($id, self::PRETTY_DATE);
            } else {
                $idAsString = sprintf('object("%s")', \get_class($id));
            }

            $id = sprintf('%s => %s', $field, $idAsString);
        });

        return sprintf('object("%s") identified by (%s)', $idClass, implode(', ', $identifiers));
    }

    private function getEntityToValidate($data, UniqueEntity $constraint, ClassMetadata $class, ObjectManager $em): object
    {
        $identity = [];
        foreach ((array) $constraint->identifierFieldNames as $fieldKey => $fieldName) {
            $this->assertEntityFieldExists($class, $fieldName);
            $propertyName = \is_string($fieldKey) ? $fieldKey : $fieldName;
            $identity[$fieldName] = $this->getDataValue($data, $propertyName, $constraint);
        }

        if (\count($identity) > 0) {
            if (array_values($class->getIdentifierFieldNames()) != array_keys($identity)) {
                throw new ConstraintDefinitionException(sprintf('The "%s" entity identifier field names should be "%s", not "%s".', $class->getName(), implode(', ', $class->getIdentifierFieldNames()), implode(', ', $constraint->identifierFieldNames)));
            }
            $entity = $em->find($class->getName(), $identity);
        }

        if (!isset($entity)) {
            $entity = $class->newInstance();
        }

        foreach ((array) $constraint->fields as $fieldKey => $fieldName) {
            $this->assertEntityFieldExists($class, $fieldName);
            $propertyName = \is_string($fieldKey) ? $fieldKey : $fieldName;
            if ($propertyName !== $fieldName && null === $constraint->errorPath) {
                $constraint->errorPath = $propertyName;
            }
            $class->reflFields[$fieldName]->setValue($entity, $this->getDataValue($data, $propertyName, $constraint));
        }

        return $entity;
    }

    private function assertEntityFieldExists(ClassMetadata $class, string $fieldName)
    {
        if (!$class->hasField($fieldName) && !$class->hasAssociation($fieldName)) {
            throw new ConstraintDefinitionException(sprintf('The field "%s" is not mapped by Doctrine entity "%s".', $fieldName, $class->getName()));
        }
    }

    private function getDataValue($data, string $propertyName, UniqueEntity $constraint)
    {
        if (\is_object($data)) {
            return $this->getPropertyValue($data, $propertyName);
        }

        $value = $this->getPropertyAccessor()->getValue($data, $propertyName);

        if (null === $value && $constraint->ignoreNull === false) {
            throw new ConstraintDefinitionException(sprintf('Cannot read path "%s" from "%s".', $propertyName, json_encode($data)));
        }

        return $value;
    }

    private function getPropertyValue(object $object, string $propertyName)
    {
        try {
            $property = new \ReflectionProperty($object, $propertyName);
        } catch (\ReflectionException $e) {
             throw new ConstraintDefinitionException(sprintf('Cannot read property "%s" from class "%s".', $propertyName, \get_class($object)));
        }
        if (!$property->isPublic()) {
            $property->setAccessible(true);
        }

        return $property->getValue($object);
    }

    private function getPropertyAccessor(): PropertyAccessorInterface
    {
        if (null === $this->propertyAccessor) {
            if (!class_exists(PropertyAccess::class)) {
                throw new \LogicException('The UniqueValueInEntityValidator requires the "PropertyAccess" component to validate non object data. Install "symfony/property-access" to use it.');
            }

            $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
        }

        return $this->propertyAccessor;
    }
}
