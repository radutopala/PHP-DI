<?php
/**
 * PHP-DI
 *
 * @link      http://mnapoli.github.io/PHP-DI/
 * @copyright Matthieu Napoli (http://mnapoli.fr/)
 * @license   http://www.opensource.org/licenses/mit-license.php MIT (see the LICENSE file)
 */

namespace DI\Definition\Source;

use DI\Definition\Definition;
use DI\Definition\ValueDefinition;

/**
 * A source that merges the definitions of several sub-sources
 *
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class CombinedDefinitionSource implements DefinitionSource
{

    /**
     * Sub-sources
     * @var DefinitionSource[]
     */
    private $subSources = array();

    /**
     * {@inheritdoc}
     */
    public function getDefinition($name)
    {
        /** @var $definition Definition|null */
        $definition = null;

        foreach ($this->subSources as $subSource) {
            $subDefinition = $subSource->getDefinition($name);

            if (!$subDefinition) {
                continue;
            }

            // A ValueDefinition always prevails on others
            // @see https://github.com/mnapoli/PHP-DI/issues/70
            if ($subDefinition instanceof ValueDefinition) {
                return $subDefinition;
            }

            if ($definition === null) {
                $definition = $subDefinition;
            } else {
                // Merge the definitions
                $definition->merge($subDefinition);
            }
        }

        return $definition;
    }

    /**
     * @return DefinitionSource[]
     */
    public function getSources()
    {
        return $this->subSources;
    }

    /**
     * @param DefinitionSource $source
     */
    public function removeSource(DefinitionSource $source)
    {
        foreach ($this->subSources as $key => $subSource) {
            if ($subSource === $source) {
                unset($this->subSources[$key]);
            }
        }
    }

    /**
     * Add a definition source to the stack
     * @param DefinitionSource $source
     */
    public function addSource($source)
    {
        $this->subSources[] = $source;
    }

}
