<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Exception;

use Symfony\Component\Cache\Exception\InvalidArgumentException as SymfonyInvalidArgumentException;

/**
 * Extends Symfony's exception so callers already catching the component's
 * hierarchy keep catching ours.
 */
class InvalidArgumentException extends SymfonyInvalidArgumentException
{
}
