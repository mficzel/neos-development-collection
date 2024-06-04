<?php

namespace Neos\Fusion\Afx\Optimization\Specifications;

use Neos\Fusion\Core\ObjectTreeParser\Ast\AbstractNode;
use Neos\Fusion\Core\ObjectTreeParser\Ast\AbstractStatement;
use Neos\Fusion\Core\ObjectTreeParser\Ast\BoolValue;
use Neos\Fusion\Core\ObjectTreeParser\Ast\FusionObjectValue;
use Neos\Fusion\Core\ObjectTreeParser\Ast\MetaPathSegment;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ObjectStatement;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ValueAssignment;

class OptimizableJoinSpecification
{
    public static function isSatisfiedBy(ObjectStatement $statement): bool
    {
//        \Neos\Flow\var_dump($statement);
        $result = self::isSatisfiedByInternal($statement);
//        \Neos\Flow\var_dump($result);
        return $result;
    }

    /**
     * Verify that:
     * - Object Statement is a 'Neos.Fusion:Join'
     * - sortProperties = false
     * - no other meta properties are set
     */
    public static function isSatisfiedByInternal(ObjectStatement $statement): bool
    {

        if ($statement->operation instanceof ValueAssignment
            && $statement->operation->pathValue instanceof FusionObjectValue
            && $statement->operation->pathValue->value === 'Neos.Fusion:Join'
        ) {
            /**
             * @var AbstractStatement[] $metaPathStatements
             */
            $metaPathStatements = [];
            foreach ($statement->block->statementList->statements as $childStatement) {
                if ($childStatement instanceof ObjectStatement
                    && $childStatement->path->segments[0] instanceof MetaPathSegment
                ) {
                    $metaPathStatements[] = $childStatement;
                }
            }

            if (count($metaPathStatements) === 1
                && $metaPathStatements[0]->path->segments[0] instanceof MetaPathSegment
                && $metaPathStatements[0]->operation->pathValue instanceof BoolValue
                && $metaPathStatements[0]->operation->pathValue?->value === false
            ) {
                return true;
            }
        }
        return false;
    }
}
