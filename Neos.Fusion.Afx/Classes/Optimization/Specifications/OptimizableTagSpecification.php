<?php

namespace Neos\Fusion\Afx\Optimization\Specifications;

use Neos\Fusion\Core\ObjectTreeParser\Ast\AbstractNode;
use Neos\Fusion\Core\ObjectTreeParser\Ast\FusionObjectValue;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ObjectStatement;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ValueAssignment;

class OptimizableTagSpecification
{
    public static function isSatisfiedBy(ObjectStatement $statement): bool
    {
        /** @todo check: no meta properties, no dynamic tagName  */
        return $statement->operation instanceof ValueAssignment
            && $statement->operation->pathValue instanceof FusionObjectValue
            && $statement->operation->pathValue->value === 'Neos.Fusion:Tag'
        ;
    }
}
