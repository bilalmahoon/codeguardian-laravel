<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Tests\Unit\Analyzers;

use CodeGuardian\Laravel\Analyzers\ArchitectureAnalyzer;
use CodeGuardian\Laravel\Analyzers\BaseAnalyzer;
use CodeGuardian\Laravel\Analyzers\PerformanceAnalyzer;
use CodeGuardian\Laravel\Analyzers\SecurityAnalyzer;
use CodeGuardian\Laravel\Analyzers\TechDebtAnalyzer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Regression test for:
 *   "FatalError: Access level to SecurityAnalyzer::calculateScore() must be
 *    protected (as in class BaseAnalyzer) or weaker"
 *
 * PHP's visibility contract: a child class method may NOT narrow the visibility
 * of a parent method (public > protected; protected cannot become private).
 * This test catches the error locally before the package reaches user projects.
 */
class AnalyzerVisibilityTest extends TestCase
{
    private array $concreteAnalyzers = [
        SecurityAnalyzer::class,
        PerformanceAnalyzer::class,
        ArchitectureAnalyzer::class,
        TechDebtAnalyzer::class,
    ];

    /**
     * Every method declared on BaseAnalyzer must NOT be narrowed in any child.
     */
    public function test_child_analyzers_do_not_narrow_base_method_visibility(): void
    {
        $baseRef     = new ReflectionClass(BaseAnalyzer::class);
        $baseMethods = [];

        foreach ($baseRef->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== BaseAnalyzer::class) {
                continue;
            }
            $baseMethods[$method->getName()] = $method->isPublic()
                ? ReflectionMethod::IS_PUBLIC
                : ($method->isProtected() ? ReflectionMethod::IS_PROTECTED : ReflectionMethod::IS_STATIC);
        }

        foreach ($this->concreteAnalyzers as $analyzerClass) {
            $childRef = new ReflectionClass($analyzerClass);
            $short    = class_basename($analyzerClass);

            foreach ($baseMethods as $methodName => $baseFlag) {
                if (! $childRef->hasMethod($methodName)) {
                    continue; // not overridden → fine
                }

                $childMethod = $childRef->getMethod($methodName);

                if ($baseFlag === ReflectionMethod::IS_PUBLIC) {
                    $this->assertTrue(
                        $childMethod->isPublic(),
                        "{$short}::{$methodName}() narrows visibility from public"
                    );
                } elseif ($baseFlag === ReflectionMethod::IS_PROTECTED) {
                    $this->assertFalse(
                        $childMethod->isPrivate(),
                        "{$short}::{$methodName}() is private but BaseAnalyzer declares it protected — " .
                        "PHP will throw a FatalError at runtime."
                    );
                }
            }
        }
    }

    /**
     * Specifically guard the calculateScore / buildSummary methods that caused
     * the regression (they were private in child classes, protected in parent).
     */
    public function test_calculate_score_is_not_private_in_child_analyzers(): void
    {
        foreach ($this->concreteAnalyzers as $analyzerClass) {
            $ref   = new ReflectionClass($analyzerClass);
            $short = class_basename($analyzerClass);

            if ($ref->hasMethod('calculateScore')) {
                $method = $ref->getMethod('calculateScore');
                $this->assertFalse(
                    $method->isPrivate(),
                    "{$short}::calculateScore() must not be private (parent is protected)."
                );
            }

            if ($ref->hasMethod('buildSummary')) {
                $method = $ref->getMethod('buildSummary');
                $this->assertFalse(
                    $method->isPrivate(),
                    "{$short}::buildSummary() must not be private (parent is protected)."
                );
            }
        }
    }

    /**
     * All concrete analyzers must be instantiable without fatal errors.
     * This catches any class-load-time PHP FatalErrors (visibility, missing
     * abstract methods, etc.) before the package is installed in user projects.
     */
    public function test_all_concrete_analyzers_can_be_instantiated(): void
    {
        foreach ($this->concreteAnalyzers as $analyzerClass) {
            $short = class_basename($analyzerClass);
            try {
                $instance = new $analyzerClass();
                $this->assertInstanceOf(BaseAnalyzer::class, $instance, "{$short} must extend BaseAnalyzer");
            } catch (\Throwable $e) {
                $this->fail("Failed to instantiate {$short}: " . $e->getMessage());
            }
        }
    }

    /**
     * All concrete analyzers must implement the analyze() method.
     */
    public function test_all_concrete_analyzers_implement_analyze_method(): void
    {
        foreach ($this->concreteAnalyzers as $analyzerClass) {
            $short = class_basename($analyzerClass);
            $ref   = new ReflectionClass($analyzerClass);

            $this->assertTrue(
                $ref->hasMethod('analyze'),
                "{$short} must implement analyze()"
            );

            $analyzeMethod = $ref->getMethod('analyze');
            $this->assertTrue(
                $analyzeMethod->isPublic(),
                "{$short}::analyze() must be public"
            );
        }
    }
}
