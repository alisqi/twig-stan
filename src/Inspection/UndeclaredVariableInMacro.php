<?php

namespace AlisQI\TwigStan\Inspection;

use Twig\Environment;
use Twig\Node\Expression\ArrowFunctionExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\ForNode;
use Twig\Node\MacroNode;
use Twig\Node\Node;
use Twig\Node\SetNode;
use Twig\NodeVisitor\AbstractNodeVisitor;

class UndeclaredVariableInMacro extends AbstractNodeVisitor
{
    private ?string $currentMacro = null;

    /** @var string[] */
    private array $declaredVariableNames = [];

    protected function doEnterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof MacroNode) {
            $this->currentMacro = $node->getAttribute('name');
            $this->declaredVariableNames = []; // reset declared variables (macro arguments will be added below)
        }
        
        $this->declaredVariableNames = array_merge(
            $this->declaredVariableNames,
            $this->getDeclaredVariablesFor($node)
        );
        
        if (
            $this->currentMacro &&
            $node instanceof NameExpression &&
            $node->isSimple()
        ) {
            $this->checkVariableIsDeclared($node->getAttribute('name'));
        }

        return $node;
    }

    protected function doLeaveNode(Node $node, Environment $env): Node
    {
        if ($node instanceof MacroNode) {
            $this->currentMacro = null;
            // no need to unset any variables, as that will happen in doEnterNode
            return $node;
        }

        if (!($node instanceof SetNode)) { // variables declared in {% set %} are never unset
            $variablesToUnset = $this->getDeclaredVariablesFor($node);
            $this->declaredVariableNames = array_filter(
                $this->declaredVariableNames,
                static fn($variable) => !in_array($variable, $variablesToUnset, true)
            );
        }

        return $node;
    }

    /**
     * @return string[]
     */
    private function getDeclaredVariablesFor(Node $node): array
    {
        $variables = [];
        
        // Strategy pattern would be overkill here (for now, at least)
        if ($node instanceof MacroNode) {
            foreach ($node->getNode('arguments') as $name => $default) {
                $variables[] = $name;
            }
        } else if (
            $node instanceof SetNode ||
            $node instanceof ArrowFunctionExpression
        ) {
            foreach ($node->getNode('names') as $nameNode) {
                $variables[] = $nameNode->getAttribute('name');
            }
        } else if ($node instanceof ForNode) {
            $variables += [
                'loop',
                $valueVariable = $node->getNode('value_target')->getAttribute('name'),
            ];
            
            // add key variable if it's declared (the node always exists)
            if ($valueVariable !== ($keyVariable = $node->getNode('key_target')->getAttribute('name'))) {
                $variables[] = $keyVariable;
            }
        }
        
        return $variables;
    }
    
    private function checkVariableIsDeclared(string $variableName): void
    {
        if (!in_array($variableName, $this->declaredVariableNames, false)) {
            trigger_error(sprintf(
                'The macro "%s" uses an undeclared variable named "%s".',
                $this->currentMacro,
                $variableName
            ), E_USER_WARNING);
        }
    }

    public function getPriority(): int
    {
        return 0;
    }
}
