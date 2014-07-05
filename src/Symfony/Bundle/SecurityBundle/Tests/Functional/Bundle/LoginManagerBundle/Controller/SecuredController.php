<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\SecurityBundle\Tests\Functional\Bundle\LoginManagerBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\Security\Core\User\User;

class SecuredController extends ContainerAware
{
    public function indexAction()
    {
        return new Response("Secured area");
    }
}
