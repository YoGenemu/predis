<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Connection;

use InvalidArgumentException;
use ReflectionClass;
use Predis\Command;

/**
 * Standard connection factory for creating connections to Redis nodes.
 *
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class Factory implements FactoryInterface
{
    protected $schemes = array(
        'tcp'  => 'Predis\Connection\StreamConnection',
        'unix' => 'Predis\Connection\StreamConnection',
        'http' => 'Predis\Connection\WebdisConnection',
    );

    /**
     * Checks if the provided argument represents a valid connection class
     * implementing Predis\Connection\SingleConnectionInterface. Optionally,
     * callable objects are used for lazy initialization of connection objects.
     *
     * @param mixed $initializer FQN of a connection class or a callable for lazy initialization.
     * @return mixed
     */
    protected function checkInitializer($initializer)
    {
        if (is_callable($initializer)) {
            return $initializer;
        }

        $class = new ReflectionClass($initializer);

        if (!$class->isSubclassOf('Predis\Connection\SingleConnectionInterface')) {
            throw new InvalidArgumentException(
                'A connection initializer must be a valid connection class or a callable object'
            );
        }

        return $initializer;
    }

    /**
     * {@inheritdoc}
     */
    public function define($scheme, $initializer)
    {
        $this->schemes[$scheme] = $this->checkInitializer($initializer);
    }

    /**
     * {@inheritdoc}
     */
    public function undefine($scheme)
    {
        unset($this->schemes[$scheme]);
    }

    /**
     * {@inheritdoc}
     */
    public function create($parameters)
    {
        if (!$parameters instanceof ParametersInterface) {
            $parameters = Parameters::create($parameters);
        }

        $scheme = $parameters->scheme;

        if (!isset($this->schemes[$scheme])) {
            throw new InvalidArgumentException("Unknown connection scheme: $scheme");
        }

        $initializer = $this->schemes[$scheme];

        if (is_callable($initializer)) {
            $connection = call_user_func($initializer, $parameters, $this);
        } else {
            $connection = new $initializer($parameters);
            $this->prepareConnection($connection);
        }

        if (!$connection instanceof SingleConnectionInterface) {
            throw new InvalidArgumentException(
                'Objects returned by connection initializers must implement ' .
                'Predis\Connection\SingleConnectionInterface'
            );
        }

        return $connection;
    }

    /**
     * {@inheritdoc}
     */
    public function aggregate(AggregateConnectionInterface $connection, array $parameters)
    {
        foreach ($parameters as $node) {
            $connection->add($node instanceof SingleConnectionInterface ? $node : $this->create($node));
        }
    }

    /**
     * Prepares a connection instance after its initialization.
     *
     * @param SingleConnectionInterface $connection Connection instance.
     */
    protected function prepareConnection(SingleConnectionInterface $connection)
    {
        $parameters = $connection->getParameters();

        if (isset($parameters->password)) {
            $connection->addConnectCommand(
                new Command\RawCommand(array('AUTH', $parameters->password))
            );
        }

        if (isset($parameters->database)) {
            $connection->addConnectCommand(
                new Command\RawCommand(array('SELECT', $parameters->database))
            );
        }
    }
}
