<?php

namespace Neos\Fusion\Afx\Optimization\Specifications;

use Neos\Fusion\Core\ObjectTreeParser\Ast\AbstractNode;
use Neos\Fusion\Core\ObjectTreeParser\Ast\BoolValue;
use Neos\Fusion\Core\ObjectTreeParser\Ast\FusionObjectValue;
use Neos\Fusion\Core\ObjectTreeParser\Ast\MetaPathSegment;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ObjectStatement;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ValueAssignment;

class OptimizableJoinSpecification
{
    public static function isSatisfiedBy(ObjectStatement $statement): bool
    {
        if ($statement->operation instanceof ValueAssignment
            && $statement->operation->pathValue instanceof FusionObjectValue
            && $statement->operation->pathValue->value === 'Neos.Fusion:Join'
        ) {
            foreach ($statement->block->statementList as $childStatement) {
                if ($childStatement instanceof ObjectStatement
                    && $childStatement->path->segments[0] instanceof MetaPathSegment
                    && (
                        $childStatement->path->segments !== [new MetaPathSegment('sortProperties')]
                        || !$childStatement->operation->pathValue instanceof BoolValue
                        || $childStatement->operation->pathValue?->value !== false
                    )
                ) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }
}
