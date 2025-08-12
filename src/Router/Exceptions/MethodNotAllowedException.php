<?php
declare(strict_types=1);

namespace TrackPHP\Router\Exceptions;

final class MethodNotAllowedException extends \RuntimeException
{
    /** @var string[] */
    private array $allowed;

    /**
     * @param string[] $allowed
     */
    public function __construct(string $method, string $path, array $allowed)
    {
        $this->allowed = array_values(array_unique(array_map('strtoupper', $allowed)));
        parent::__construct(sprintf('Method %s not allowed for %s', $method, $path), 405);
    }

    /** @return string[] */
    public function allowed(): array
    {
        return $this->allowed;
    }
}
