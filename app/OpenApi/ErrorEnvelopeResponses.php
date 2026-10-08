<?php

declare(strict_types=1);

namespace App\OpenApi;

use App\Exceptions\ApiException;
use App\Http\Errors\ErrorCode;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Teaches Scramble the error envelope, replacing its built-in Laravel shapes
 * (`{ message, errors }`) for the exceptions it infers from controllers,
 * FormRequests and policies. Registered after the built-ins, so it wins.
 */
final class ErrorEnvelopeResponses extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $this->codeFor($type) !== null;
    }

    public function toResponse(Type $type): ?Response
    {
        $code = $this->codeFor($type);
        if ($code === null) {
            return null;
        }

        // fromType() is untyped upstream; check what it built rather than trust it.
        $schema = Schema::fromType(self::envelope($code));
        if (! $schema instanceof Schema) {
            throw new LogicException('Scramble did not build a Schema.');
        }

        return (new Response($code->status()))
            ->setDescription($code->defaultMessage())
            ->setContent('application/json', $schema);
    }

    public function reference(ObjectType $type): ?Reference
    {
        $code = $this->codeFor($type);

        return $code === null ? null : new Reference('responses', 'Error.'.$code->value, $this->components);
    }

    /*
     * Scramble's builders mostly lack return types, so these are built
     * statement by statement rather than chained.
     */
    public static function envelope(ErrorCode $code): OpenApiTypes\ObjectType
    {
        $details = new OpenApiTypes\ObjectType;

        if ($code === ErrorCode::Validation) {
            $messages = new OpenApiTypes\ArrayType;
            $messages->setItems(new OpenApiTypes\StringType);

            $fields = new OpenApiTypes\ObjectType;
            $fields->setDescription('Messages per failing field.');
            $fields->additionalProperties($messages);

            $details->addProperty('fields', $fields);
            $details->setRequired(['fields']);
        } else {
            $details->setDescription('Optional machine-readable context.');
        }

        $error = new OpenApiTypes\ObjectType;
        $error->addProperty('code', (new OpenApiTypes\StringType)->enum([$code->value]));
        $error->addProperty('message', (new OpenApiTypes\StringType)->setDescription('Human-readable; never branch on it.'));
        $error->addProperty('details', $details);
        $error->setRequired($code === ErrorCode::Validation ? ['code', 'message', 'details'] : ['code', 'message']);

        $envelope = new OpenApiTypes\ObjectType;
        $envelope->addProperty('error', $error);
        $envelope->setRequired(['error']);

        return $envelope;
    }

    private function codeFor(Type $type): ?ErrorCode
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        return match (true) {
            $type->isInstanceOf(ValidationException::class) => ErrorCode::Validation,
            $type->isInstanceOf(AuthenticationException::class) => ErrorCode::Unauthenticated,
            $type->isInstanceOf(AuthorizationException::class) => ErrorCode::Forbidden,
            $type->isInstanceOf(ModelNotFoundException::class),
            $type->isInstanceOf(RecordsNotFoundException::class),
            $type->isInstanceOf(NotFoundHttpException::class) => ErrorCode::NotFound,
            $type->isInstanceOf(ApiException::class) => self::apiExceptionCode($type->name),
            default => null,
        };
    }

    private static function apiExceptionCode(string $class): ?ErrorCode
    {
        if (! is_subclass_of($class, ApiException::class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);

        return $reflection->isAbstract() ? null : $reflection->newInstanceWithoutConstructor()->errorCode();
    }
}
