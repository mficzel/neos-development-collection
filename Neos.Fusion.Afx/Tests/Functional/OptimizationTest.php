<?php
namespace Neos\Fusion\Afx\Tests\Functional;

use Neos\Fusion\Afx\Optimization\AstOptimizer;
use Neos\Fusion\Core\FusionSourceCode;
use Neos\Fusion\Core\ObjectTreeParser\ObjectTreeParser;
use PHPUnit\Framework\TestCase;

class OptimizationTest extends TestCase
{
    /**
     */
    public function simpleAssignmentCannotBeOptimized(): void
    {
        $fusionCode = 'test="bar"';
        $optimizedFusion = 'test="bar"';
        $this->verifyFusionOptimization($fusionCode, $optimizedFusion);
    }

    /**
     * @test
     */
    public function nestedJoinsAreCompacted(): void
    {
        $fusionCode = <<<EOF
            test = Neos.Fusion:Join {
                @sortProperties = false
                1 = "foo"
                2 = Neos.Fusion:Join {
                    @sortProperties = false
                    1 = "foo"
                    2 = "bar"
                    3 = Neos.Fusion:Join {
                        @sortProperties = false
                        1 = "aaa"
                        2 = "bbb"
                    }
                }
                3 = "baz"
            }
            EOF;

        $optimizedFusion = <<<EOF
            test = Neos.Fusion:Join {
                @sortProperties = false
                1 = "foo"
                2_1 = "foo"
                2_2 = "bar"
                2_3_1 = "aaa"
                2_3_2 = "bbb"
                3 = "baz"
            }
            EOF;

        $this->verifyFusionOptimization($fusionCode, $optimizedFusion);
    }

    /**
     */
    public function expandJoinsWithOneItemAndRemoveEmptyJoins(): void
    {
        $fusionCode = <<<EOF
            test = Neos.Fusion:Join {
                @sortProperties = false
                1 = Neos.Fusion:Tag {
                    content = Neos.Fusion:Join {
                        @sortProperties = false
                        1 = "aaa"
                    }
                }
                2 = Neos.Fusion:Tag {
                    content = Neos.Fusion:Join {
                        @sortProperties = false
                    }
                }

            }
            EOF;

        $optimizedFusion = <<<EOF
            test = Neos.Fusion:Join {
                @sortProperties = false
                1 = Neos.Fusion:Tag {
                    content = "aaa"
                }
                2 = Neos.Fusion:Tag {
                }
            }
            EOF;

        $this->verifyFusionOptimization($fusionCode, $optimizedFusion);
    }

    /**
     */
    public function nestedJoinsInsideTageAreCompacted(): void
    {
        $fusionCode = <<<EOF
            test = Neos.Fusion:Tag {
                content = Neos.Fusion:Join {
                    @sortProperties = false
                    1 = "foo"
                    2 = Neos.Fusion:Join {
                        @sortProperties = false
                        1 = "foo"
                        2 = "bar"
                        3 = Neos.Fusion:Tag {
                            content = Neos.Fusion:Join {
                                @sortProperties = false
                                1 = "aaa"
                                2 = "bbb"
                                3 = Neos.Fusion:Join {
                                    @sortProperties = false
                                    1 = "ccc"
                                    2 = "ddd"
                                }
                            }
                        }
                    }
                    3 = "baz"
                }
            }
            EOF;

        $optimizedFusion = <<<EOF
            test = Neos.Fusion:Tag {
                content = Neos.Fusion:Join {
                    @sortProperties = false
                    1 = "foo"
                    2_1 = "foo"
                    2_2 = "bar"
                    2_3 = Neos.Fusion:Tag {
                        content = Neos.Fusion:Join {
                            @sortProperties = false
                            1 = "aaa"
                            2 = "bbb"
                            3_1 = "ccc"
                            3_2 = "ddd"
                        }
                    }
                    3 = "baz"
                }
            }
            EOF;

        $this->verifyFusionOptimization($fusionCode, $optimizedFusion);
    }

    private function verifyFusionOptimization(string $unoptimizedFusion, string $optimizedFusion): void
    {
        $parsingResult = ObjectTreeParser::parse(FusionSourceCode::fromString($unoptimizedFusion));
        $optimizedResult = AstOptimizer::optimizeFusionFile($parsingResult);
        $expectedResult = ObjectTreeParser::parse(FusionSourceCode::fromString($optimizedFusion));
        $this->assertEquals($expectedResult, $optimizedResult);
    }
}
