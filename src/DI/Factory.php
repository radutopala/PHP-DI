<?php
/**
 * PHP-DI
 *
 * @link      http://mnapoli.github.io/PHP-DI/
 * @copyright Matthieu Napoli (http://mnapoli.fr/)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT (see the LICENSE file)
 */

namespace DI;

use DI\Definition\ClassDefinition;
use DI\Definition\Exception\DefinitionException;
use DI\Definition\MethodInjection;
use DI\Definition\PropertyInjection;
use Exception;
use ReflectionClass;
use ReflectionProperty;

/**
 * Factory class, responsible of instantiating classes
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class Factory implements FactoryInterface
{

    /**
     * @var Container
     */
    protected $container;

    /**
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * {@inheritdoc}
     * @throws DependencyException
     * @throws \DI\Definition\Exception\DefinitionException
     */
    public function createInstance(ClassDefinition $classDefinition)
    {
        $classReflection = new ReflectionClass($classDefinition->getClassName());

        if (!$classReflection->isInstantiable()) {
            throw new DependencyException("$classReflection->name is not instantiable");
        }

        // Create an instance without calling its constructor
        $instance = $this->newInstanceWithoutConstructor($classReflection);

        try {
            // Property injections
            foreach ($classDefinition->getPropertyInjections() as $propertyInjection) {
                $this->injectProperty($instance, $propertyInjection);
            }

            // Constructor injection
            $this->injectConstructor($instance, $classReflection, $classDefinition->getConstructorInjection());

            // Method injections
            foreach ($classDefinition->getMethodInjections() as $methodInjection) {
                $this->injectMethod($instance, $methodInjection);
            }
        } catch (DependencyException $e) {
            throw $e;
        } catch (DefinitionException $e) {
            throw $e;
        } catch (NotFoundException $e) {
            throw new DependencyException("Error while injecting dependencies into $classReflection->name: " . $e->getMessage(), 0, $e);
        }

        return $instance;
    }

    /**
     * Inject dependencies through the constructor
     *
     * @param object               $object Object to inject dependencies into
     * @param ReflectionClass      $classReflection
     * @param MethodInjection|null $constructorInjection
     *
     * @throws DefinitionException
     */
    private function injectConstructor(
        $object,
        ReflectionClass $classReflection,
        MethodInjection $constructorInjection = null
    ) {
        $constructorReflection = $classReflection->getConstructor();

        // No constructor
        if (!$constructorReflection) {
            return;
        }

        // Check the definition and the class parameter number match
        $nbRequiredParameters = $constructorReflection->getNumberOfRequiredParameters();
        $parameterInjections = $constructorInjection ? $constructorInjection->getParameterInjections() : array();
        if (count($parameterInjections) < $nbRequiredParameters) {
            throw new DefinitionException("The constructor of {$classReflection->name} takes "
                . "$nbRequiredParameters parameters, " . count($parameterInjections) . " defined or guessed");
        }

        if (count($parameterInjections) === 0) {
            $constructorReflection->invoke($object);
            return;
        }

        $args = array();
        foreach ($parameterInjections as $parameterInjection) {
            $entryName = $parameterInjection->getEntryName();
            if ($entryName === null) {
                throw new DefinitionException("The parameter '" . $parameterInjection->getParameterName()
                    . "' of the constructor of '{$classReflection->name}' has no type defined or guessable");
            }

            $args[] = $this->container->get($entryName);
        }

        $constructorReflection->invokeArgs($object, $args);
    }

    /**
     * Inject dependencies through methods
     *
     * @param object          $object Object to inject dependencies into
     * @param MethodInjection $methodInjection
     *
     * @throws DependencyException
     * @throws DefinitionException
     */
    private function injectMethod($object, MethodInjection $methodInjection)
    {
        $methodName = $methodInjection->getMethodName();
        $classReflection = new ReflectionClass($object);
        $methodReflection = $classReflection->getMethod($methodName);

        // Check the definition and the class parameter number match
        $nbRequiredParameters = $methodReflection->getNumberOfRequiredParameters();
        $parameterInjections = $methodInjection ? $methodInjection->getParameterInjections() : array();
        if (count($parameterInjections) < $nbRequiredParameters) {
            throw new DefinitionException("{$classReflection->name}::$methodName takes "
                . "$nbRequiredParameters parameters, " . count($parameterInjections) . " defined or guessed");
        }

        // No parameters
        if (count($parameterInjections) === 0) {
            $methodReflection->invoke($object);
            return;
        }

        $args = array();
        foreach ($parameterInjections as $parameterInjection) {
            $entryName = $parameterInjection->getEntryName();
            if ($entryName === null) {
                throw new DefinitionException("The parameter '" . $parameterInjection->getParameterName()
                    . "' of {$classReflection->name}::$methodName has no type defined or guessable");
            }

            $args[] = $this->container->get($entryName);
        }

        $methodReflection->invokeArgs($object, $args);
    }

    /**
     * Inject dependencies into properties
     *
     * @param object            $object            Object to inject dependencies into
     * @param PropertyInjection $propertyInjection Property injection definition
     *
     * @throws DependencyException
     * @throws DefinitionException
     */
    private function injectProperty($object, PropertyInjection $propertyInjection)
    {
        $propertyName = $propertyInjection->getPropertyName();
        $property = new ReflectionProperty(get_class($object), $propertyName);

        $entryName = $propertyInjection->getEntryName();
        if ($entryName === null) {
            throw new DefinitionException(get_class($object) . "::$propertyName has no type defined or guessable");
        }

        // Get the dependency
        try {
            $value = $this->container->get($propertyInjection->getEntryName(), $propertyInjection->isLazy());
        } catch (DependencyException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new DependencyException("Error while injecting '" . $propertyInjection->getEntryName() . "' in "
                . get_class($object) . "::$propertyName. " . $e->getMessage(), 0, $e);
        }

        // Allow access to protected and private properties
        $property->setAccessible(true);

        // Inject the dependency
        $property->setValue($object, $value);
    }

    /**
     * Creates a new instance of a class without calling its constructor
     *
     * @param ReflectionClass $classReflection
     *
     * @return mixed
     */
    private function newInstanceWithoutConstructor(ReflectionClass $classReflection)
    {
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            // Create a new class instance without calling the constructor (PHP 5.4 magic)
            return $classReflection->newInstanceWithoutConstructor();
        } else {
            $classname = $classReflection->name;
            return unserialize(
                sprintf(
                    'O:%d:"%s":0:{}',
                    strlen($classname),
                    $classname
                )
            );
        }
    }

}
