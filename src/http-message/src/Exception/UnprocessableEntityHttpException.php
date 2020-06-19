<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\HttpMessage\Exception;

class UnprocessableEntityHttpException extends HttpException
{
    public function __construct($message = null, $code = 0, \Exception $previous = null)
    {
        parent::__construct(422, $message, $code, $previous);
    }
}
