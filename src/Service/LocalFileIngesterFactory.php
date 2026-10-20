<?php
namespace BatchValues\Service;

use BatchValues\Media\LocalFileIngester;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class LocalFileIngesterFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new LocalFileIngester($services);
    }
}
