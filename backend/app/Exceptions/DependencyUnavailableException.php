<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpFoundation\Response;

class DependencyUnavailableException extends ApiException
{
    public function errorCode(): string
    {
        return 'dependency_unavailable';
    }

    public function status(): int
    {
        return Response::HTTP_SERVICE_UNAVAILABLE;
    }
}
