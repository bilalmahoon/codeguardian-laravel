<?php

declare(strict_types=1);

namespace CodeGuardian\Laravel\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Accurate, AST-based code metrics (via nikic/php-parser). Unlike regex
 * counting, these ignore keywords that appear inside strings/comments and
 * understand real language structure — fewer false positives.
 *
 * Built on CachedPhpParser so parsing is shared/cached across the run.
 */
final class AstUtil
{
    /**
     * Cyclomatic complexity of a whole file or snippet: 1 + number of decision
     * points (if/elseif/for/foreach/while/do/case/catch/ternary/??/&&/||, and
     * each match arm). Returns null when the code cannot be parsed.
     */
    public static function complexity(string $code): ?int
    {
        $ast = CachedPhpParser::parse(self::ensurePhpTag($code));
        if ($ast === null) {
            return null;
        }
        return 1 + self::countDecisionPoints($ast);
    }

    /**
     * Per-method metrics with accurate line ranges and complexity.
     *
     * @return array<int,array{name:string,start_line:int,end_line:int,complexity:int,params:int}>|null
     */
    public static function methods(string $code): ?array
    {
        $ast = CachedPhpParser::parse(self::ensurePhpTag($code));
        if ($ast === null) {
            return null;
        }

        $finder  = new NodeFinder();
        $methods = [];

        /** @var Node\Stmt\ClassMethod[] $nodes */
        $nodes = $finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);
        foreach ($nodes as $method) {
            $body = $method->stmts ?? [];
            $methods[] = [
                'name'       => $method->name->toString(),
                'start_line' => $method->getStartLine(),
                'end_line'   => $method->getEndLine(),
                'complexity' => 1 + self::countDecisionPoints($body),
                'params'     => count($method->params),
            ];
        }

        return $methods;
    }

    /**
     * Count decision-point nodes within an AST subtree.
     *
     * @param array<int,Node> $nodes
     */
    public static function countDecisionPoints(array $nodes): int
    {
        $finder = new NodeFinder();
        $count  = 0;

        $branchTypes = [
            Node\Stmt\If_::class,
            Node\Stmt\ElseIf_::class,
            Node\Stmt\For_::class,
            Node\Stmt\Foreach_::class,
            Node\Stmt\While_::class,
            Node\Stmt\Do_::class,
            Node\Stmt\Catch_::class,
            Node\Expr\Ternary::class,
            Node\Expr\BinaryOp\BooleanAnd::class,
            Node\Expr\BinaryOp\BooleanOr::class,
            Node\Expr\BinaryOp\Coalesce::class,
        ];
        foreach ($branchTypes as $type) {
            $count += count($finder->findInstanceOf($nodes, $type));
        }

        // switch "case X:" (default has no cond → not a branch)
        foreach ($finder->findInstanceOf($nodes, Node\Stmt\Case_::class) as $case) {
            if ($case->cond !== null) {
                $count++;
            }
        }

        // match arms with conditions
        foreach ($finder->findInstanceOf($nodes, Node\MatchArm::class) as $arm) {
            if (! empty($arm->conds)) {
                $count += count($arm->conds);
            }
        }

        return $count;
    }

    private static function ensurePhpTag(string $code): string
    {
        return str_contains($code, '<?php') ? $code : "<?php\n" . $code;
    }
}
