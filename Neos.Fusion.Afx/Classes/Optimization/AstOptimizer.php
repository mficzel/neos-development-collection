<?php
declare(strict_types=1);

namespace Neos\Fusion\Afx\Optimization;

use Neos\Fusion\Afx\Optimization\Specifications\OptimizableJoinSpecification;
use Neos\Fusion\Core\ObjectTreeParser\Ast\Block;
use Neos\Fusion\Core\ObjectTreeParser\Ast\BoolValue;
use Neos\Fusion\Core\ObjectTreeParser\Ast\FusionFile;
use Neos\Fusion\Core\ObjectTreeParser\Ast\FusionObjectValue;
use Neos\Fusion\Core\ObjectTreeParser\Ast\MetaPathSegment;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ObjectPath;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ObjectStatement;
use Neos\Fusion\Core\ObjectTreeParser\Ast\PathSegment;
use Neos\Fusion\Core\ObjectTreeParser\Ast\StatementList;
use Neos\Fusion\Core\ObjectTreeParser\Ast\ValueAssignment;

class AstOptimizer
{
    public static function optimizeFusionFile(FusionFile $fusionFile): FusionFile
    {
        return new FusionFile(
            self::optimizeTagStatements(
                self::optimizeJoinStatements(
                    $fusionFile->statementList
                )
            ),
            $fusionFile->contextPathAndFileName
        );
    }

    public static function optimizeJoinStatements (StatementList $statementList): StatementList
    {
        $optimizedStatements = [];
        foreach ($statementList->statements as $statement) {
            if ($statement instanceof ObjectStatement
                && OptimizableJoinSpecification::isSatisfiedBy($statement)
            ) {
                $childStatementList = self::optimizeJoinStatements($statement->block->statementList);
                $childStatements = $childStatementList->statements;
                $childStatementsOptimized = [];

                foreach ($childStatements as $childStatement) {
                    if ($childStatement instanceof ObjectStatement
                        && OptimizableJoinSpecification::isSatisfiedBy($childStatement)
                    ) {
                        foreach ($childStatement->block->statementList->statements as $grandChildStatement) {
                            if ($grandChildStatement instanceof ObjectStatement) {
                                if ($grandChildStatement->path->segments === [new MetaPathSegment('sortProperties')]
                                    && $grandChildStatement->operation->pathValue instanceof BoolValue
                                    && $grandChildStatement->operation->pathValue->value === false
                                ) {
                                    \Neos\Flow\var_dump("skip meta sort");
                                    \Neos\Flow\var_dump($grandChildStatement);
                                    continue;
                                }
                                $combinedPathSegment = new PathSegment( $childStatement->path->segments[0]->identifier . '_' . $grandChildStatement->path->segments[0]->identifier);
                                $childStatementsOptimized[] = new ObjectStatement(
                                    path: new ObjectPath($combinedPathSegment),
                                    operation: $grandChildStatement->operation,
                                    block: $grandChildStatement->block,
                                    cursor: $grandChildStatement->cursor
                                );
                            } else {
                                throw new \Exception('This is should never happen');
                            }
                        }
                    } else {
                        $childStatementsOptimized[] = $childStatement;
                    }
                }

                if (count($childStatementsOptimized) === 0) {
                    // ignore
                } elseif (count($childStatementsOptimized) === 1) {
                    $childStatement = $childStatementsOptimized[0];
                    $optimizedStatements[] = new ObjectStatement(
                        path: $statement->path,
                        operation: $childStatement->operation,
                        block: $childStatement->block,
                        cursor: $childStatement->cursor
                    );
                } else {
                    $optimizedStatements[] = new ObjectStatement(
                        path: $statement->path,
                        operation: $statement->operation,
                        block: new Block(
                            new StatementList(...$childStatementsOptimized)
                        ),
                        cursor: $statement->cursor
                    );
                }
            } elseif ($statement instanceof ObjectStatement) {
                $optimizedStatements[] = new ObjectStatement(
                    path: $statement->path,
                    operation: $statement->operation,
                    block: $statement->block
                        ? new Block(self::optimizeJoinStatements($statement->block->statementList))
                        : null,
                    cursor: $statement->cursor
                );
            } else {
                $optimizedStatements[] = $statement;
            }
        }
        return new StatementList(...$optimizedStatements);
    }

    public static function optimizeTagStatements (StatementList $statementList): StatementList
    {
        return $statementList;
    }

}
