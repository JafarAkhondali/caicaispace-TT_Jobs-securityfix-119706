<?php

declare(strict_types=1);
/**
 * @link https://github.com/TTSimple/TT_Jobs
 */
namespace PhpParser;

/*
 * This parser is based on a skeleton written by Moriyoshi Koizumi, which in
 * turn is based on work by Masato Bito.
 */
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\UseUse;

abstract class ParserAbstract implements Parser
{
    public const SYMBOL_NONE = -1;

    /*
     * The following members will be filled with generated parsing data:
     */

    /** @var int Size of map */
    protected $tokenToSymbolMapSize;
    /** @var int Size of table */
    protected $actionTableSize;
    /** @var int Size of table */
    protected $gotoTableSize;

    /** @var int Symbol number signifying an invalid token */
    protected $invalidSymbol;
    /** @var int Symbol number of error recovery token */
    protected $errorSymbol;
    /** @var int Action number signifying default action */
    protected $defaultAction;
    /** @var int Rule number signifying that an unexpected token was encountered */
    protected $unexpectedTokenRule;

    protected $YY2TBLSTATE;
    protected $YYNLSTATES;

    /** @var array Map of lexer tokens to internal symbols */
    protected $tokenToSymbol;
    /** @var array Map of symbols to their names */
    protected $symbolToName;
    /** @var array Names of the production rules (only necessary for debugging) */
    protected $productions;

    /** @var array Map of states to a displacement into the table. The corresponding action for this
     *             state/symbol pair is $action[$actionBase[$state] + $symbol]. If $actionBase[$state] is 0, the
     * action is defaulted, i.e. $actionDefault[$state] should be used instead. */
    protected $actionBase;
    /** @var array Table of actions. Indexed according to comment. */
    protected $action;
    /** @var array Table indexed analogously to. IfCheck[$actionBase[$state] +] !=
     *             then the action is defaulted, i.e. $actionDefault[$state] should be used instead. */
    protected $actionCheck;
    /** @var array Map of states to their default action */
    protected $actionDefault;

    /** @var array Map of non-terminals to a displacement into the table. The corresponding goto state for this
     *             non-terminal/state pair is $goto[$gotoBase[$nonTerminal] + $state] (unless defaulted) */
    protected $gotoBase;
    /** @var array Table of states to goto after reduction. Indexed according to comment. */
    protected $goto;
    /** @var array Table indexed analogously to. IfCheck[$gotoBase[$nonTerminal] +] !=
     *             then the goto state is defaulted, i.e. $gotoDefault[$nonTerminal] should be used. */
    protected $gotoCheck;
    /** @var array Map of non-terminals to the default state to goto after their reduction */
    protected $gotoDefault;

    /** @var array Map of rules to the non-terminal on their left-hand side, i.e. the non-terminal to use for
     *             determining the state to goto after reduction. */
    protected $ruleToNonTerminal;
    /** @var array Map of rules to the length of their right-hand side, which is the number of elements that have to
     *             be popped from the stack(s) on reduction. */
    protected $ruleToLength;

    /*
     * The following members are part of the parser state:
     */

    /** @var Lexer Lexer that is used when parsing */
    protected $lexer;
    /** @var mixed Temporary value containing the result of last semantic action (reduction) */
    protected $semValue;
    /** @var int Position in stacks (state stack, semantic value stack, attribute stack) */
    protected $stackPos;
    /** @var array Semantic value stack (contains values of tokens and semantic action results) */
    protected $semStack;
    /** @var array[] Start attribute stack */
    protected $startAttributeStack;
    /** @var array[] End attribute stack */
    protected $endAttributeStack;
    /** @var array End attributes of last *shifted* token */
    protected $endAttributes;
    /** @var array Start attributes of last *read* token */
    protected $lookaheadStartAttributes;

    /** @var ErrorHandler Error handler */
    protected $errorHandler;
    /** @var Error[] Errors collected during last parse */
    protected $errors;
    /** @var int Error state, used to avoid error floods */
    protected $errorState;

    protected static $specialNames = [
        'self'   => true,
        'parent' => true,
        'static' => true,
    ];

    /**
     * Creates a parser instance.
     *
     * @param Lexer $lexer A lexer
     * @param array $options Options array. Currently no options are supported.
     */
    public function __construct(Lexer $lexer, array $options = [])
    {
        $this->lexer  = $lexer;
        $this->errors = [];

        if (isset($options['throwOnError'])) {
            throw new \LogicException(
                '"throwOnError" is no longer supported, use "errorHandler" instead'
            );
        }
    }

    /**
     * Parses PHP code into a node tree.
     *
     * If a non-throwing error handler is used, the parser will continue parsing after an error
     * occurred and attempt to build a partial AST.
     *
     * @param string $code The source code to parse
     * @param null|ErrorHandler $errorHandler error handler to use for lexer/parser errors, defaults
     *                                        to ErrorHandler\Throwing
     *
     * @return null|Node[] array of statements (or null if the 'throwOnError' option is disabled and the parser was
     *                     unable to recover from an error)
     */
    public function parse($code, ErrorHandler $errorHandler = null)
    {
        $this->errorHandler = $errorHandler ?: new ErrorHandler\Throwing();

        // Initialize the lexer
        $this->lexer->startLexing($code, $this->errorHandler);

        // We start off with no lookahead-token
        $symbol = self::SYMBOL_NONE;

        // The attributes for a node are taken from the first and last token of the node.
        // From the first token only the startAttributes are taken and from the last only
        // the endAttributes. Both are merged using the array union operator (+).
        $startAttributes     = '*POISON';
        $endAttributes       = '*POISON';
        $this->endAttributes = $endAttributes;

        // Keep stack of start and end attributes
        $this->startAttributeStack = [];
        $this->endAttributeStack   = [$endAttributes];

        // Start off in the initial state and keep a stack of previous states
        $state      = 0;
        $stateStack = [$state];

        // Semantic value stack (contains values of tokens and semantic action results)
        $this->semStack = [];

        // Current position in the stack(s)
        $this->stackPos = 0;

        $this->errorState = 0;

        while (true) {
            //$this->traceNewState($state, $symbol);

            if ($this->actionBase[$state] == 0) {
                $rule = $this->actionDefault[$state];
            } else {
                if ($symbol === self::SYMBOL_NONE) {
                    // Fetch the next token id from the lexer and fetch additional info by-ref.
                    // The end attributes are fetched into a temporary variable and only set once the token is really
                    // shifted (not during read). Otherwise you would sometimes get off-by-one errors, when a rule is
                    // reduced after a token was read but not yet shifted.
                    $tokenId = $this->lexer->getNextToken($tokenValue, $startAttributes, $endAttributes);

                    // map the lexer token id to the internally used symbols
                    $symbol = $tokenId >= 0 && $tokenId < $this->tokenToSymbolMapSize
                        ? $this->tokenToSymbol[$tokenId]
                        : $this->invalidSymbol;

                    if ($symbol === $this->invalidSymbol) {
                        throw new \RangeException(sprintf(
                            'The lexer returned an invalid token (id=%d, value=%s)',
                            $tokenId,
                            $tokenValue
                        ));
                    }

                    // This is necessary to assign some meaningful attributes to /* empty */ productions. They'll get
                    // the attributes of the next token, even though they don't contain it themselves.
                    $this->startAttributeStack[$this->stackPos + 1] = $startAttributes;
                    $this->endAttributeStack[$this->stackPos + 1]   = $endAttributes;
                    $this->lookaheadStartAttributes                 = $startAttributes;

                    //$this->traceRead($symbol);
                }

                $idx = $this->actionBase[$state] + $symbol;
                if ((($idx >= 0 && $idx < $this->actionTableSize && $this->actionCheck[$idx] == $symbol)
                     || ($state < $this->YY2TBLSTATE
                         && ($idx = $this->actionBase[$state + $this->YYNLSTATES] + $symbol) >= 0
                         && $idx < $this->actionTableSize && $this->actionCheck[$idx] == $symbol))
                    && ($action = $this->action[$idx]) != $this->defaultAction) {
                    /*
                     * >= YYNLSTATES: shift and reduce
                     * > 0: shift
                     * = 0: accept
                     * < 0: reduce
                     * = -YYUNEXPECTED: error
                     */
                    if ($action > 0) {
                        /* shift */
                        //$this->traceShift($symbol);

                        ++$this->stackPos;
                        $stateStack[$this->stackPos]                = $state                = $action;
                        $this->semStack[$this->stackPos]            = $tokenValue;
                        $this->startAttributeStack[$this->stackPos] = $startAttributes;
                        $this->endAttributeStack[$this->stackPos]   = $endAttributes;
                        $this->endAttributes                        = $endAttributes;
                        $symbol                                     = self::SYMBOL_NONE;

                        if ($this->errorState) {
                            --$this->errorState;
                        }

                        if ($action < $this->YYNLSTATES) {
                            continue;
                        }

                        /* $yyn >= YYNLSTATES means shift-and-reduce */
                        $rule = $action - $this->YYNLSTATES;
                    } else {
                        $rule = -$action;
                    }
                } else {
                    $rule = $this->actionDefault[$state];
                }
            }

            while (true) {
                if ($rule === 0) {
                    /* accept */
                    //$this->traceAccept();
                    return $this->semValue;
                }
                if ($rule !== $this->unexpectedTokenRule) {
                    /* reduce */
                    //$this->traceReduce($rule);

                    try {
                        $this->{'reduceRule' . $rule}();
                    } catch (Error $e) {
                        if ($e->getStartLine() === -1 && isset($startAttributes['startLine'])) {
                            $e->setStartLine($startAttributes['startLine']);
                        }

                        $this->emitError($e);
                        // Can't recover from this type of error
                        return null;
                    }

                    /* Goto - shift nonterminal */
                    $lastEndAttributes = $this->endAttributeStack[$this->stackPos];
                    $this->stackPos -= $this->ruleToLength[$rule];
                    $nonTerminal = $this->ruleToNonTerminal[$rule];
                    $idx         = $this->gotoBase[$nonTerminal] + $stateStack[$this->stackPos];
                    if ($idx >= 0 && $idx < $this->gotoTableSize && $this->gotoCheck[$idx] == $nonTerminal) {
                        $state = $this->goto[$idx];
                    } else {
                        $state = $this->gotoDefault[$nonTerminal];
                    }

                    ++$this->stackPos;
                    $stateStack[$this->stackPos]              = $state;
                    $this->semStack[$this->stackPos]          = $this->semValue;
                    $this->endAttributeStack[$this->stackPos] = $lastEndAttributes;
                } else {
                    /* error */
                    switch ($this->errorState) {
                        case 0:
                            $msg = $this->getErrorMessage($symbol, $state);
                            $this->emitError(new Error($msg, $startAttributes + $endAttributes));
                            // Break missing intentionally
                            // no break
                        case 1:
                        case 2:
                            $this->errorState = 3;

                            // Pop until error-expecting state uncovered
                            while (! (
                                (($idx = $this->actionBase[$state] + $this->errorSymbol) >= 0
                                    && $idx < $this->actionTableSize && $this->actionCheck[$idx] == $this->errorSymbol)
                                || ($state < $this->YY2TBLSTATE
                                    && ($idx = $this->actionBase[$state + $this->YYNLSTATES] + $this->errorSymbol) >= 0
                                    && $idx < $this->actionTableSize && $this->actionCheck[$idx] == $this->errorSymbol)
                            ) || ($action = $this->action[$idx]) == $this->defaultAction) { // Not totally sure about this
                                if ($this->stackPos <= 0) {
                                    // Could not recover from error
                                    return null;
                                }
                                $state = $stateStack[--$this->stackPos];
                                //$this->tracePop($state);
                            }

                            //$this->traceShift($this->errorSymbol);
                            ++$this->stackPos;
                            $stateStack[$this->stackPos] = $state = $action;

                            // We treat the error symbol as being empty, so we reset the end attributes
                            // to the end attributes of the last non-error symbol
                            $this->endAttributeStack[$this->stackPos] = $this->endAttributeStack[$this->stackPos - 1];
                            $this->endAttributes                      = $this->endAttributeStack[$this->stackPos - 1];
                            break;
                        case 3:
                            if ($symbol === 0) {
                                // Reached EOF without recovering from error
                                return null;
                            }

                            //$this->traceDiscard($symbol);
                            $symbol = self::SYMBOL_NONE;
                            break 2;
                    }
                }

                if ($state < $this->YYNLSTATES) {
                    break;
                }

                /* >= YYNLSTATES means shift-and-reduce */
                $rule = $state - $this->YYNLSTATES;
            }
        }

        throw new \RuntimeException('Reached end of parser loop');
    }

    protected function emitError(Error $error)
    {
        $this->errorHandler->handleError($error);
    }

    protected function getErrorMessage($symbol, $state)
    {
        $expectedString = '';
        if ($expected = $this->getExpectedTokens($state)) {
            $expectedString = ', expecting ' . implode(' or ', $expected);
        }

        return 'Syntax error, unexpected ' . $this->symbolToName[$symbol] . $expectedString;
    }

    protected function getExpectedTokens($state)
    {
        $expected = [];

        $base = $this->actionBase[$state];
        foreach ($this->symbolToName as $symbol => $name) {
            $idx = $base + $symbol;
            if ($idx >= 0 && $idx < $this->actionTableSize && $this->actionCheck[$idx] === $symbol
                || $state < $this->YY2TBLSTATE
                && ($idx = $this->actionBase[$state + $this->YYNLSTATES] + $symbol) >= 0
                && $idx < $this->actionTableSize && $this->actionCheck[$idx] === $symbol
            ) {
                if ($this->action[$idx] != $this->unexpectedTokenRule
                    && $this->action[$idx] != $this->defaultAction
                    && $symbol != $this->errorSymbol
                ) {
                    if (count($expected) == 4) {
                        /* Too many expected tokens */
                        return [];
                    }

                    $expected[] = $name;
                }
            }
        }

        return $expected;
    }

    /*
     * Tracing functions used for debugging the parser.
     */

    /*
    protected function traceNewState($state, $symbol) {
        echo '% State ' . $state
            . ', Lookahead ' . ($symbol == self::SYMBOL_NONE ? '--none--' : $this->symbolToName[$symbol]) . "\n";
    }

    protected function traceRead($symbol) {
        echo '% Reading ' . $this->symbolToName[$symbol] . "\n";
    }

    protected function traceShift($symbol) {
        echo '% Shift ' . $this->symbolToName[$symbol] . "\n";
    }

    protected function traceAccept() {
        echo "% Accepted.\n";
    }

    protected function traceReduce($n) {
        echo '% Reduce by (' . $n . ') ' . $this->productions[$n] . "\n";
    }

    protected function tracePop($state) {
        echo '% Recovering, uncovered state ' . $state . "\n";
    }

    protected function traceDiscard($symbol) {
        echo '% Discard ' . $this->symbolToName[$symbol] . "\n";
    }
    */

    /*
     * Helper functions invoked by semantic actions
     */

    /**
     * Moves statements of semicolon-style namespaces into $ns->stmts and checks various error conditions.
     *
     * @param Node[] $stmts
     * @return Node[]
     */
    protected function handleNamespaces(array $stmts)
    {
        $hasErrored = false;
        $style      = $this->getNamespacingStyle($stmts);
        if ($style === null) {
            // not namespaced, nothing to do
            return $stmts;
        }
        if ($style === 'brace') {
            // For braced namespaces we only have to check that there are no invalid statements between the namespaces
            $afterFirstNamespace = false;
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\Namespace_) {
                    $afterFirstNamespace = true;
                } elseif (! $stmt instanceof Node\Stmt\HaltCompiler
                        && ! $stmt instanceof Node\Stmt\Nop
                        && $afterFirstNamespace && ! $hasErrored) {
                    $this->emitError(new Error(
                        'No code may exist outside of namespace {}',
                        $stmt->getAttributes()
                    ));
                    $hasErrored = true; // Avoid one error for every statement
                }
            }
            return $stmts;
        }
        // For semicolon namespaces we have to move the statements after a namespace declaration into ->stmts
        $resultStmts = [];
        $targetStmts = &$resultStmts;
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                if ($stmt->stmts === null) {
                    $stmt->stmts   = [];
                    $targetStmts   = &$stmt->stmts;
                    $resultStmts[] = $stmt;
                } else {
                    // This handles the invalid case of mixed style namespaces
                    $resultStmts[] = $stmt;
                    $targetStmts   = &$resultStmts;
                }
            } elseif ($stmt instanceof Node\Stmt\HaltCompiler) {
                // __halt_compiler() is not moved into the namespace
                $resultStmts[] = $stmt;
            } else {
                $targetStmts[] = $stmt;
            }
        }
        return $resultStmts;
    }

    protected function handleBuiltinTypes(Name $name)
    {
        $scalarTypes = [
            'bool'     => true,
            'int'      => true,
            'float'    => true,
            'string'   => true,
            'iterable' => true,
            'void'     => true,
            'object'   => true,
        ];

        if (! $name->isUnqualified()) {
            return $name;
        }

        $lowerName = strtolower($name->toString());
        return isset($scalarTypes[$lowerName]) ? $lowerName : $name;
    }

    protected function getAttributesAt($pos)
    {
        return $this->startAttributeStack[$pos] + $this->endAttributeStack[$pos];
    }

    protected function parseLNumber($str, $attributes, $allowInvalidOctal = false)
    {
        try {
            return LNumber::fromString($str, $attributes, $allowInvalidOctal);
        } catch (Error $error) {
            $this->emitError($error);
            // Use dummy value
            return new LNumber(0, $attributes);
        }
    }

    protected function parseNumString($str, $attributes)
    {
        if (! preg_match('/^(?:0|-?[1-9][0-9]*)$/', $str)) {
            return new String_($str, $attributes);
        }

        $num = +$str;
        if (! is_int($num)) {
            return new String_($str, $attributes);
        }

        return new LNumber($num, $attributes);
    }

    protected function checkModifier($a, $b, $modifierPos)
    {
        // Jumping through some hoops here because verifyModifier() is also used elsewhere
        try {
            Class_::verifyModifier($a, $b);
        } catch (Error $error) {
            $error->setAttributes($this->getAttributesAt($modifierPos));
            $this->emitError($error);
        }
    }

    protected function checkParam(Param $node)
    {
        if ($node->variadic && $node->default !== null) {
            $this->emitError(new Error(
                'Variadic parameter cannot have a default value',
                $node->default->getAttributes()
            ));
        }
    }

    protected function checkTryCatch(TryCatch $node)
    {
        if (empty($node->catches) && $node->finally === null) {
            $this->emitError(new Error(
                'Cannot use try without catch or finally',
                $node->getAttributes()
            ));
        }
    }

    protected function checkNamespace(Namespace_ $node)
    {
        if (isset(self::$specialNames[strtolower($node->name)])) {
            $this->emitError(new Error(
                sprintf('Cannot use \'%s\' as namespace name', $node->name),
                $node->name->getAttributes()
            ));
        }

        if ($node->stmts !== null) {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Namespace_) {
                    $this->emitError(new Error(
                        'Namespace declarations cannot be nested',
                        $stmt->getAttributes()
                    ));
                }
            }
        }
    }

    protected function checkClass(Class_ $node, $namePos)
    {
        if ($node->name !== null && isset(self::$specialNames[strtolower($node->name)])) {
            $this->emitError(new Error(
                sprintf('Cannot use \'%s\' as class name as it is reserved', $node->name),
                $this->getAttributesAt($namePos)
            ));
        }

        if (isset(self::$specialNames[strtolower($node->extends)])) {
            $this->emitError(new Error(
                sprintf('Cannot use \'%s\' as class name as it is reserved', $node->extends),
                $node->extends->getAttributes()
            ));
        }

        foreach ($node->implements as $interface) {
            if (isset(self::$specialNames[strtolower($interface)])) {
                $this->emitError(new Error(
                    sprintf('Cannot use \'%s\' as interface name as it is reserved', $interface),
                    $interface->getAttributes()
                ));
            }
        }
    }

    protected function checkInterface(Interface_ $node, $namePos)
    {
        if ($node->name !== null && isset(self::$specialNames[strtolower($node->name)])) {
            $this->emitError(new Error(
                sprintf('Cannot use \'%s\' as class name as it is reserved', $node->name),
                $this->getAttributesAt($namePos)
            ));
        }

        foreach ($node->extends as $interface) {
            if (isset(self::$specialNames[strtolower($interface)])) {
                $this->emitError(new Error(
                    sprintf('Cannot use \'%s\' as interface name as it is reserved', $interface),
                    $interface->getAttributes()
                ));
            }
        }
    }

    protected function checkClassMethod(ClassMethod $node, $modifierPos)
    {
        if ($node->flags & Class_::MODIFIER_STATIC) {
            switch (strtolower($node->name)) {
                case '__construct':
                    $this->emitError(new Error(
                        sprintf('Constructor %s() cannot be static', $node->name),
                        $this->getAttributesAt($modifierPos)
                    ));
                    break;
                case '__destruct':
                    $this->emitError(new Error(
                        sprintf('Destructor %s() cannot be static', $node->name),
                        $this->getAttributesAt($modifierPos)
                    ));
                    break;
                case '__clone':
                    $this->emitError(new Error(
                        sprintf('Clone method %s() cannot be static', $node->name),
                        $this->getAttributesAt($modifierPos)
                    ));
                    break;
            }
        }
    }

    protected function checkClassConst(ClassConst $node, $modifierPos)
    {
        if ($node->flags & Class_::MODIFIER_STATIC) {
            $this->emitError(new Error(
                "Cannot use 'static' as constant modifier",
                $this->getAttributesAt($modifierPos)
            ));
        }
        if ($node->flags & Class_::MODIFIER_ABSTRACT) {
            $this->emitError(new Error(
                "Cannot use 'abstract' as constant modifier",
                $this->getAttributesAt($modifierPos)
            ));
        }
        if ($node->flags & Class_::MODIFIER_FINAL) {
            $this->emitError(new Error(
                "Cannot use 'final' as constant modifier",
                $this->getAttributesAt($modifierPos)
            ));
        }
    }

    protected function checkProperty(Property $node, $modifierPos)
    {
        if ($node->flags & Class_::MODIFIER_ABSTRACT) {
            $this->emitError(new Error(
                'Properties cannot be declared abstract',
                $this->getAttributesAt($modifierPos)
            ));
        }

        if ($node->flags & Class_::MODIFIER_FINAL) {
            $this->emitError(new Error(
                'Properties cannot be declared final',
                $this->getAttributesAt($modifierPos)
            ));
        }
    }

    protected function checkUseUse(UseUse $node, $namePos)
    {
        if (strtolower($node->alias) == 'self' || strtolower($node->alias) == 'parent') {
            $this->emitError(new Error(
                sprintf(
                    'Cannot use %s as %s because \'%2$s\' is a special class name',
                    $node->name,
                    $node->alias
                ),
                $this->getAttributesAt($namePos)
            ));
        }
    }

    private function getNamespacingStyle(array $stmts)
    {
        $style              = null;
        $hasNotAllowedStmts = false;
        foreach ($stmts as $i => $stmt) {
            if ($stmt instanceof Node\Stmt\Namespace_) {
                $currentStyle = $stmt->stmts === null ? 'semicolon' : 'brace';
                if ($style === null) {
                    $style = $currentStyle;
                    if ($hasNotAllowedStmts) {
                        $this->emitError(new Error(
                            'Namespace declaration statement has to be the very first statement in the script',
                            $stmt->getLine() // Avoid marking the entire namespace as an error
                        ));
                    }
                } elseif ($style !== $currentStyle) {
                    $this->emitError(new Error(
                        'Cannot mix bracketed namespace declarations with unbracketed namespace declarations',
                        $stmt->getLine() // Avoid marking the entire namespace as an error
                    ));
                    // Treat like semicolon style for namespace normalization
                    return 'semicolon';
                }
                continue;
            }

            /* declare(), __halt_compiler() and nops can be used before a namespace declaration */
            if ($stmt instanceof Node\Stmt\Declare_
                || $stmt instanceof Node\Stmt\HaltCompiler
                || $stmt instanceof Node\Stmt\Nop) {
                continue;
            }

            /* There may be a hashbang line at the very start of the file */
            if ($i == 0 && $stmt instanceof Node\Stmt\InlineHTML && preg_match('/\A#!.*\r?\n\z/', $stmt->value)) {
                continue;
            }

            /* Everything else if forbidden before namespace declarations */
            $hasNotAllowedStmts = true;
        }
        return $style;
    }
}
