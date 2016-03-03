<?php
/**
 * LimeSurvey
 * Copyright (C) 2007-2013 The LimeSurvey Project Team / Carsten Schmitz
 * All rights reserved.
 * License: GNU/GPL License v2 or later, see LICENSE.php
 * LimeSurvey is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See COPYRIGHT.php for copyright notices and details.
 *
 */
use ls\models\Token;

/**
 * Description of ExpressionManager
 * (1) Does safe evaluation of PHP expressions.  Only registered Functions, and known Variables are allowed.
 *   (a) Functions include any math, string processing, conditional, formatting, etc. functions
 * (2) This class replaces LimeSurvey's <= 1.91+  process of resolving strings that contain LimeReplacementFields
 *   (a) String is split by expressions (by curly braces, but safely supporting strings and escaped curly braces)
 *   (b) Expressions (things surrounded by curly braces) are evaluated - thereby doing LimeReplacementField substitution and/or more complex calculations
 *   (c) Non-expressions are left intact
 *   (d) The array of stringParts are re-joined to create the desired final string.
 * (3) The core of Expression Manager is a Recursive Descent Parser (RDP), based off of one build via JavaCC by TMSWhite in 1999.
 *   (a) Functions that start with RDP_ should not be touched unless you really understand compiler design.
 *
 * @author LimeSurvey Team (limesurvey.org)
 * @author Thomas M. White (TMSWhite)
 */

class ExpressionManager {
    // These are the allowable suffixes for variables - each represents an attribute of a variable.
    static $RDP_regex_var_attr = 'code|gid|grelevance|gseq|jsName|mandatory|NAOK|qid|qseq|question|readWrite|relevanceStatus|relevance|rowdivid|sgqa|shown|type|valueNAOK|value';

    // These three variables are effectively static once constructed
    private $RDP_ExpressionRegex;
    private $RDP_TokenType;
    private $RDP_TokenizerRegex;
    private $RDP_CategorizeTokensRegex;
    private $RDP_ValidFunctions; // names and # params of valid functions

    // Thes variables are used while  processing the equation
    private $RDP_expr;  // the source expression
    private $RDP_tokens;    // the list of generated tokens
    private $RDP_count; // total number of $RDP_tokens
    private $RDP_pos;   // position within the $token array while processing equation
    private $RDP_errs = [];    // array of syntax errors
    private $RDP_onlyparse;
    private $RDP_stack; // stack of intermediate results
    private $RDP_result;    // final result of evaluating the expression;
    private $RDP_evalStatus;    // true if $RDP_result is a valid result, and  there are no serious errors
    private $varsUsed = [];  // list of variables referenced in the equation

    // These  variables are only used by sProcessStringContainingExpressions
    private $allVarsUsed;   // full list of variables used within the string, even if contains multiple expressions
    private $prettyPrintSource; // HTML formatted output of running sProcessStringContainingExpressions

    private $questionSeq;   // sequence order of question - so can detect if try to use variable before it is set
    private $groupSeq;  // sequence order of groups - so can detect if try to use variable before it is set

    // The following are only needed to enable click on variable names within pretty print and open new window to edit them
    private $sgqaNaming=false;

    /**
     * @var callable
     */
    protected $variableGetter;

    /**
     * @param callable $questionGetter
     */
    protected $questionGetter;

    protected function getQuestionByCode($code)
    {
        if (isset($this->questionGetter)) {
            $getter = $this->questionGetter;

            return $getter($code);
        }
    }
    public function __construct(callable $variableGetter = null, callable $getQuestionByCode = null)
    {
        $this->variableGetter = $variableGetter;
        $this->questionGetter = $getQuestionByCode;
        // List of token-matching regular expressions
        // Note, this is effectively a Lexer using Regular Expressions.  Don't change this unless you understand compiler design.
        $RDP_regex_dq_string = '(?<!\\\\)".*?(?<!\\\\)"';
        $RDP_regex_sq_string = '(?<!\\\\)\'.*?(?<!\\\\)\'';
        $RDP_regex_whitespace = '\s+';
        $RDP_regex_lparen = '\(';
        $RDP_regex_rparen = '\)';
        $RDP_regex_comma = ',';
        $RDP_regex_not = '!';
        $RDP_regex_inc_dec = '\+\+|--';
        $RDP_regex_binary = '[+*/-]';
        $RDP_regex_compare = '<=|<|>=|>|==|!=|\ble\b|\blt\b|\bge\b|\bgt\b|\beq\b|\bne\b';
        $RDP_regex_assign = '=';    // '=|\+=|-=|\*=|/=';
        $RDP_regex_sgqa = '(?:INSERTANS:)?[0-9]+X[0-9]+X[0-9]+[A-Z0-9_]*\#?[01]?(?:\.(?:' . ExpressionManager::$RDP_regex_var_attr . '))?';
        $RDP_regex_word = '(?:TOKEN:)?(?:[A-Z][A-Z0-9_]*)?(?:\.(?:[A-Z][A-Z0-9_]*))*(?:\.(?:' . ExpressionManager::$RDP_regex_var_attr . '))?';
        $RDP_regex_number = '[0-9]+\.?[0-9]*|\.[0-9]+';
        $RDP_regex_andor = '\band\b|\bor\b|&&|\|\|';
        $RDP_regex_lcb = '{';
        $RDP_regex_rcb = '}';
        $RDP_regex_sq = '\'';
        $RDP_regex_dq= '"';
        $RDP_regex_bs = '\\\\';

        $RDP_StringSplitRegex = [
            $RDP_regex_lcb,
            $RDP_regex_rcb,
            $RDP_regex_sq,
            $RDP_regex_dq,
            $RDP_regex_bs,
        ];

        // RDP_ExpressionRegex is the regular expression that splits apart strings that contain curly braces in order to find expressions
        $this->RDP_ExpressionRegex =  '#(' . implode('|',$RDP_StringSplitRegex) . ')#i';

        // asTokenRegex and RDP_TokenType must be kept in sync  (same number and order)
        $RDP_TokenRegex = [
            $RDP_regex_dq_string,
            $RDP_regex_sq_string,
            $RDP_regex_whitespace,
            $RDP_regex_lparen,
            $RDP_regex_rparen,
            $RDP_regex_comma,
            $RDP_regex_andor,
            $RDP_regex_compare,
            $RDP_regex_sgqa,
            $RDP_regex_word,
            $RDP_regex_number,
            $RDP_regex_not,
            $RDP_regex_inc_dec,
            $RDP_regex_assign,
            $RDP_regex_binary,
        ];

        $this->RDP_TokenType = [
            'DQ_STRING',
            'SQ_STRING',
            'SPACE',
            'LP',
            'RP',
            'COMMA',
            'AND_OR',
            'COMPARE',
            'SGQA',
            'WORD',
            'NUMBER',
            'NOT',
            'OTHER',
            'ASSIGN',
            'BINARYOP',
        ];

        // $RDP_TokenizerRegex - a single regex used to split and equation into tokens
        $this->RDP_TokenizerRegex = '#(' . implode('|',$RDP_TokenRegex) . ')#i';

        // $RDP_CategorizeTokensRegex - an array of patterns so can categorize the type of token found - would be nice if could get this from preg_split
        // Adding ability to capture 'OTHER' type, which indicates an error - unsupported syntax element
        $this->RDP_CategorizeTokensRegex = preg_replace("#^(.*)$#","#^$1$#i",$RDP_TokenRegex);
        $this->RDP_CategorizeTokensRegex[] = '/.+/';
        $this->RDP_TokenType[] = 'OTHER';

        // Each allowed function is a mapping from local name to external name + number of arguments
        // Functions can have a list of serveral allowable #s of arguments.
        // If the value is -1, the function must have a least one argument but can have an unlimited number of them
        // -2 means that at least one argument is required.  -3 means at least two arguments are required, etc.
        $this->RDP_ValidFunctions = [
            'abs' => ['abs', 'Math.abs', gT('Absolute value'), 'number abs(number)', 'http://www.php.net/manual/en/function.checkdate.php', 1],
            'acos' => ['acos', 'Math.acos', gT('Arc cosine'), 'number acos(number)', 'http://www.php.net/manual/en/function.acos.php', 1],
            'addslashes' => ['addslashes', gT('addslashes'), 'Quote string with slashes', 'string addslashes(string)', 'http://www.php.net/manual/en/function.addslashes.php', 1],
            'asin' => ['asin', 'Math.asin', gT('Arc sine'), 'number asin(number)', 'http://www.php.net/manual/en/function.asin.php', 1],
            'atan' => ['atan', 'Math.atan', gT('Arc tangent'), 'number atan(number)', 'http://www.php.net/manual/en/function.atan.php', 1],
            'atan2' => ['atan2', 'Math.atan2', gT('Arc tangent of two variables'), 'number atan2(number, number)', 'http://www.php.net/manual/en/function.atan2.php', 2],
            'ceil' => ['ceil', 'Math.ceil', gT('Round fractions up'), 'number ceil(number)', 'http://www.php.net/manual/en/function.ceil.php', 1],
            'checkdate' => ['checkdate', 'checkdate', gT('Returns true(1) if it is a valid date in gregorian calendar'), 'bool checkdate(month,day,year)', 'http://www.php.net/manual/en/function.checkdate.php', 3],
            'cos' => ['cos', 'Math.cos', gT('Cosine'), 'number cos(number)', 'http://www.php.net/manual/en/function.cos.php', 1],
            'count' => ['exprmgr_count', 'LEMcount', gT('Count the number of answered questions in the list'), 'number count(arg1, arg2, ... argN)', '', -1],
            'countif' => ['exprmgr_countif', 'LEMcountif', gT('Count the number of answered questions in the list equal the first argument'), 'number countif(matches, arg1, arg2, ... argN)', '', -2],
            'countifop' => ['exprmgr_countifop', 'LEMcountifop', gT('Count the number of answered questions in the list which pass the critiera (arg op value)'), 'number countifop(op, value, arg1, arg2, ... argN)', '', -3],
            'date' => ['date', 'date', gT('Format a local date/time'), 'string date(format [, timestamp=time()])', 'http://www.php.net/manual/en/function.date.php', 1,2],
            'exp' => ['exp', 'Math.exp', gT('Calculates the exponent of e'), 'number exp(number)', 'http://www.php.net/manual/en/function.exp.php', 1],
            'fixnum' => ['exprmgr_fixnum', 'LEMfixnum', gT('Display numbers with comma as decimal separator, if needed'), 'string fixnum(number)', '', 1],
            'floor' => ['floor', 'Math.floor', gT('Round fractions down'), 'number floor(number)', 'http://www.php.net/manual/en/function.floor.php', 1],
            'gmdate' => ['gmdate', 'gmdate', gT('Format a GMT date/time'), 'string gmdate(format [, timestamp=time()])', 'http://www.php.net/manual/en/function.gmdate.php', 1,2],
            'html_entity_decode' => ['html_entity_decode', 'html_entity_decode', gT('Convert all HTML entities to their applicable characters (always uses ENT_QUOTES and UTF-8)'), 'string html_entity_decode(string)', 'http://www.php.net/manual/en/function.html-entity-decode.php', 1],
            'htmlentities' => ['htmlentities', 'htmlentities', gT('Convert all applicable characters to HTML entities (always uses ENT_QUOTES and UTF-8)'), 'string htmlentities(string)', 'http://www.php.net/manual/en/function.htmlentities.php', 1],
            'htmlspecialchars' => ['expr_mgr_htmlspecialchars', 'htmlspecialchars', gT('Convert special characters to HTML entities (always uses ENT_QUOTES and UTF-8)'), 'string htmlspecialchars(string)', 'http://www.php.net/manual/en/function.htmlspecialchars.php', 1],
            'htmlspecialchars_decode' => ['expr_mgr_htmlspecialchars_decode', 'htmlspecialchars_decode', gT('Convert special HTML entities back to characters (always uses ENT_QUOTES and UTF-8)'), 'string htmlspecialchars_decode(string)', 'http://www.php.net/manual/en/function.htmlspecialchars-decode.php', 1],
            'idate' => ['idate', 'idate', gT('Format a local time/date as integer'), 'string idate(string [, timestamp=time()])', 'http://www.php.net/manual/en/function.idate.php', 1,2],
            'if' => ['exprmgr_if', 'LEMif', gT('Conditional processing'), 'if(test,result_if_true,result_if_false)', '', 3],
            'implode' => ['exprmgr_implode', 'LEMimplode', gT('Join array elements with a string'), 'string implode(glue,arg1,arg2,...,argN)', 'http://www.php.net/manual/en/function.implode.php', -2],
            'intval' => ['intval', 'LEMintval', gT('Get the integer value of a variable'), 'int intval(number [, base=10])', 'http://www.php.net/manual/en/function.intval.php', 1,2],
            'is_empty' => ['exprmgr_empty', 'LEMempty', gT('Determine whether a variable is considered to be empty'), 'bool is_empty(var)', 'http://www.php.net/manual/en/function.empty.php', 1],
            'is_float' => ['is_float', 'LEMis_float', gT('Finds whether the type of a variable is float'), 'bool is_float(var)', 'http://www.php.net/manual/en/function.is-float.php', 1],
            'is_int' => ['exprmgr_int', 'LEMis_int', gT('Find whether the type of a variable is integer'), 'bool is_int(var)', 'http://www.php.net/manual/en/function.is-int.php', 1],
            'is_nan' => ['is_nan', 'isNaN', gT('Finds whether a value is not a number'), 'bool is_nan(var)', 'http://www.php.net/manual/en/function.is-nan.php', 1],
            'is_null' => ['is_null', 'LEMis_null', gT('Finds whether a variable is NULL'), 'bool is_null(var)', 'http://www.php.net/manual/en/function.is-null.php', 1],
            'is_numeric' => ['is_numeric', 'LEMis_numeric', gT('Finds whether a variable is a number or a numeric string'), 'bool is_numeric(var)', 'http://www.php.net/manual/en/function.is-numeric.php', 1],
            'is_string' => ['is_string', 'LEMis_string', gT('Find whether the type of a variable is string'), 'bool is_string(var)', 'http://www.php.net/manual/en/function.is-string.php', 1],
            'join' => ['exprmgr_join', 'LEMjoin', gT('Join strings, return joined string.This function is an alias of implode("",argN)'), 'string join(arg1,arg2,...,argN)', '', -1],
            'list' => ['exprmgr_list', 'LEMlist', gT('Return comma-separated list of values'), 'string list(arg1, arg2, ... argN)', '', -2],
            'log' => ['exprmgr_log', 'LEMlog', gT('The logarithm of number to base, if given, or the natural logarithm. '), 'number log(number,base=e)', 'http://www.php.net/manual/en/function.log.php', -2],
            'ltrim' => ['ltrim', 'ltrim', gT('Strip whitespace (or other characters) from the beginning of a string'), 'string ltrim(string [, charlist])', 'http://www.php.net/manual/en/function.ltrim.php', 1,2],
            'max' => ['max', 'Math.max', gT('Find highest value'), 'number max(arg1, arg2, ... argN)', 'http://www.php.net/manual/en/function.max.php', -2],
            'min' => ['min', 'Math.min', gT('Find lowest value'), 'number min(arg1, arg2, ... argN)', 'http://www.php.net/manual/en/function.min.php', -2],
            'mktime' => ['mktime', 'mktime', gT('Get UNIX timestamp for a date (each of the 6 arguments are optional)'), 'number mktime([hour [, minute [, second [, month [, day [, year ]]]]]])', 'http://www.php.net/manual/en/function.mktime.php', 0,1,2,3,4,5,6],
            'nl2br' => ['nl2br', 'nl2br', gT('Inserts HTML line breaks before all newlines in a string'), 'string nl2br(string)', 'http://www.php.net/manual/en/function.nl2br.php', 1,1],
            'number_format' => ['number_format', 'number_format', gT('Format a number with grouped thousands'), 'string number_format(number)', 'http://www.php.net/manual/en/function.number-format.php', 1],
            'pi' => ['pi', 'LEMpi', gT('Get value of pi'), 'number pi()', '', 0],
            'pow' => ['pow', 'Math.pow', gT('Exponential expression'), 'number pow(base, exp)', 'http://www.php.net/manual/en/function.pow.php', 2],
            'quoted_printable_decode' => ['quoted_printable_decode', 'quoted_printable_decode', gT('Convert a quoted-printable string to an 8 bit string'), 'string quoted_printable_decode(string)', 'http://www.php.net/manual/en/function.quoted-printable-decode.php', 1],
            'quoted_printable_encode' => ['quoted_printable_encode', 'quoted_printable_encode', gT('Convert a 8 bit string to a quoted-printable string'), 'string quoted_printable_encode(string)', 'http://www.php.net/manual/en/function.quoted-printable-encode.php', 1],
            'quotemeta' => ['quotemeta', 'quotemeta', gT('Quote meta characters'), 'string quotemeta(string)', 'http://www.php.net/manual/en/function.quotemeta.php', 1],
            'rand' => ['rand', 'rand', gT('Generate a random integer'), 'int rand() OR int rand(min, max)', 'http://www.php.net/manual/en/function.rand.php', 0,2],
            'regexMatch' => ['exprmgr_regexMatch', 'LEMregexMatch', gT('Compare a string to a regular expression pattern'), 'bool regexMatch(pattern,input)', '', 2],
            'round' => ['round', 'round', gT('Rounds a number to an optional precision'), 'number round(val [, precision])', 'http://www.php.net/manual/en/function.round.php', 1,2],
            'rtrim' => ['rtrim', 'rtrim', gT('Strip whitespace (or other characters) from the end of a string'), 'string rtrim(string [, charlist])', 'http://www.php.net/manual/en/function.rtrim.php', 1,2],
            'sin' => ['sin', 'Math.sin', gT('Sine'), 'number sin(arg)', 'http://www.php.net/manual/en/function.sin.php', 1],
            'sprintf' => ['sprintf', 'sprintf', gT('Return a formatted string'), 'string sprintf(format, arg1, arg2, ... argN)', 'http://www.php.net/manual/en/function.sprintf.php', -2],
            'sqrt' => ['sqrt', 'Math.sqrt', gT('Square root'), 'number sqrt(arg)', 'http://www.php.net/manual/en/function.sqrt.php', 1],
            'stddev' => ['exprmgr_stddev', 'LEMstddev', gT('Calculate the Sample Standard Deviation for the list of numbers'), 'number stddev(arg1, arg2, ... argN)', '', -2],
            'str_pad' => ['str_pad', 'str_pad', gT('Pad a string to a certain length with another string'), 'string str_pad(input, pad_length [, pad_string])', 'http://www.php.net/manual/en/function.str-pad.php', 2,3],
            'str_repeat' => ['str_repeat', 'str_repeat', gT('Repeat a string'), 'string str_repeat(input, multiplier)', 'http://www.php.net/manual/en/function.str-repeat.php', 2],
            'str_replace' => ['str_replace', 'LEMstr_replace', gT('Replace all occurrences of the search string with the replacement string'), 'string str_replace(search,  replace, subject)', 'http://www.php.net/manual/en/function.str-replace.php', 3],
            'strcasecmp' => ['strcasecmp', 'strcasecmp', gT('Binary safe case-insensitive string comparison'), 'int strcasecmp(str1, str2)', 'http://www.php.net/manual/en/function.strcasecmp.php', 2],
            'strcmp' => ['strcmp', 'strcmp', gT('Binary safe string comparison'), 'int strcmp(str1, str2)', 'http://www.php.net/manual/en/function.strcmp.php', 2],
            'strip_tags' => ['strip_tags', 'strip_tags', gT('Strip HTML and PHP tags from a string'), 'string strip_tags(str, allowable_tags)', 'http://www.php.net/manual/en/function.strip-tags.php', 1,2],
            'stripos' => ['exprmgr_stripos', 'stripos', gT('Find position of first occurrence of a case-insensitive string'), 'int stripos(haystack, needle [, offset=0])', 'http://www.php.net/manual/en/function.stripos.php', 2,3],
            'stripslashes' => ['stripslashes', 'stripslashes', gT('Un-quotes a quoted string'), 'string stripslashes(string)', 'http://www.php.net/manual/en/function.stripslashes.php', 1],
            'stristr' => ['exprmgr_stristr', 'stristr', gT('Case-insensitive strstr'), 'string stristr(haystack, needle [, before_needle=false])', 'http://www.php.net/manual/en/function.stristr.php', 2,3],
            'strlen' => ['exprmgr_strlen', 'LEMstrlen', gT('Get string length'), 'int strlen(string)', 'http://www.php.net/manual/en/function.strlen.php', 1],
            'strpos' => ['exprmgr_strpos', 'LEMstrpos', gT('Find position of first occurrence of a string'), 'int strpos(haystack, needle [ offset=0])', 'http://www.php.net/manual/en/function.strpos.php', 2,3],
            'strrev' => ['strrev', 'strrev', gT('Reverse a string'), 'string strrev(string)', 'http://www.php.net/manual/en/function.strrev.php', 1],
            'strstr' => ['exprmgr_strstr', 'strstr', gT('Find first occurrence of a string'), 'string strstr(haystack, needle [, before_needle=false])', 'http://www.php.net/manual/en/function.strstr.php', 2,3],
            'strtolower' => ['exprmgr_strtolower', 'LEMstrtolower', gT('Make a string lowercase'), 'string strtolower(string)', 'http://www.php.net/manual/en/function.strtolower.php', 1],
            'strtotime' => ['strtotime', 'strtotime', gT('Convert a date/time string to unix timestamp'), 'int strtotime(string)', 'http://www.php.net/manual/de/function.strtotime.php', 1],
            'strtoupper' => ['exprmgr_strtoupper', 'LEMstrtoupper', gT('Make a string uppercase'), 'string strtoupper(string)', 'http://www.php.net/manual/en/function.strtoupper.php', 1],
            'substr' => ['exprmgr_substr', 'substr', gT('Return part of a string'), 'string substr(string, start [, length])', 'http://www.php.net/manual/en/function.substr.php', 2,3],
            'sum' => ['array_sum', 'LEMsum', gT('Calculate the sum of values in an array'), 'number sum(arg1, arg2, ... argN)', '', -2],
            'sumifop' => ['exprmgr_sumifop', 'LEMsumifop', gT('Sum the values of answered questions in the list which pass the critiera (arg op value)'), 'number sumifop(op, value, arg1, arg2, ... argN)', '', -3],
            'tan' => ['tan', 'Math.tan', gT('Tangent'), 'number tan(arg)', 'http://www.php.net/manual/en/function.tan.php', 1],
            'convert_value' => ['exprmgr_convert_value', 'LEMconvert_value', gT('Convert a numerical value using a inputTable and outputTable of numerical values'), 'number convert_value(fValue, iStrict, sTranslateFromList, sTranslateToList)', '', 4],
            'time' => ['time', 'time', gT('Return current UNIX timestamp'), 'number time()', 'http://www.php.net/manual/en/function.time.php', 0],
            'trim' => ['trim', 'trim', gT('Strip whitespace (or other characters) from the beginning and end of a string'), 'string trim(string [, charlist])', 'http://www.php.net/manual/en/function.trim.php', 1,2],
            'ucwords' => ['ucwords', 'ucwords', gT('Uppercase the first character of each word in a string'), 'string ucwords(string)', 'http://www.php.net/manual/en/function.ucwords.php', 1],
            'unique' => ['exprmgr_unique', 'LEMunique', gT('Returns true if all non-empty responses are unique'), 'boolean unique(arg1, ..., argN)', '', -1],
        ];

    }

    /**
     * Add an error to the error log
     *
     * @param <type> $errMsg
     * @param <type> $token
     */
    private function RDP_AddError($errMsg, $token)
    {
        $this->RDP_errs[] = [$errMsg, $token];
    }

    /**
     * RDP_EvaluateBinary() computes binary expressions, such as (a or b), (c * d), popping  the top two entries off the
     * stack and pushing the result back onto the stack.
     *
     * @param array $token
     * @return boolean - false if there is any error, else true
     */

     private function RDP_EvaluateBinary(array $token)
    {
        if (count($this->RDP_stack) < 2)
        {
            $this->RDP_AddError(gT("Unable to evaluate binary operator - fewer than 2 entries on stack"), $token);
            return false;
        }
        $arg2 = $this->RDP_StackPop();
        $arg1 = $this->RDP_StackPop();
        if (is_null($arg1) or is_null($arg2))
        {
            $this->RDP_AddError(gT("Invalid value(s) on the stack"), $token);
            return false;
        }

        $bNumericArg1 = (is_numeric($arg1[0]) || $arg1[0] == '');
        $bNumericArg2 = (is_numeric($arg2[0]) || $arg2[0] == '');

        $bStringArg1 = (!$bNumericArg1 || $arg1[0] == '');
        $bStringArg2 = (!$bNumericArg2 || $arg2[0] == '');

        $bBothNumeric = ($bNumericArg1 && $bNumericArg2);
        $bBothString = ($bStringArg1 && $bStringArg2);
        $bMismatchType=(!$bBothNumeric && !$bBothString);

        // Set bBothString if one is forced to be string, only if bith can be numeric. Mimic JS and PHO
        // Not sure if needed to test if [2] is set. : TODO review
        if($bBothNumeric){
            $aForceStringArray= ['DQ_STRING','DS_STRING','STRING'];// ls\models\Question can return NUMERIC or WORD : DQ and DS is string entered by user, STRING is a result of a String function
            if( (isset($arg1[2]) && in_array($arg1[2],$aForceStringArray) || (isset($arg2[2]) && in_array($arg2[2],$aForceStringArray)) ) )
            {
                $bBothNumeric=false;
                $bBothString=true;
                $bMismatchType=false;
                $arg1[0]=strval($arg1[0]);
                $arg2[0]=strval($arg2[0]);
            }
        }
        switch(strtolower($token[0]))
        {
            case 'or':
            case '||':
                $result = [($arg1[0] or $arg2[0]),$token[1],'NUMBER'];
                break;
            case 'and':
            case '&&':
                $result = [($arg1[0] and $arg2[0]),$token[1],'NUMBER'];
                break;
            case '==':
            case 'eq':
                $result = [($arg1[0] == $arg2[0]),$token[1],'NUMBER'];
                break;
            case '!=':
            case 'ne':
                $result = [($arg1[0] != $arg2[0]),$token[1],'NUMBER'];
                break;
            case '<':
            case 'lt':
                if ($bMismatchType) {
                    $result = [false,$token[1],'NUMBER'];
                }
                else {
                    $result = [($arg1[0] < $arg2[0]),$token[1],'NUMBER'];
                }
                break;
            case '<=';
            case 'le':
                if ($bMismatchType) {
                    $result = [false,$token[1],'NUMBER'];
                }
                else {
                    // Need this explicit comparison in order to be in agreement with JavaScript
                    if (($arg1[0] == '0' && $arg2[0] == '') || ($arg1[0] == '' && $arg2[0] == '0')) {
                        $result = [true,$token[1],'NUMBER'];
                    }
                    else {
                        $result = [($arg1[0] <= $arg2[0]),$token[1],'NUMBER'];
                    }
                }
                break;
            case '>':
            case 'gt':
                if ($bMismatchType) {
                    $result = [false,$token[1],'NUMBER'];
                }
                else {
                    // Need this explicit comparison in order to be in agreement with JavaScript
                    if (($arg1[0] == '0' && $arg2[0] == '') || ($arg1[0] == '' && $arg2[0] == '0')) {
                        $result = [false,$token[1],'NUMBER'];
                    }
                    else {
                        $result = [($arg1[0] > $arg2[0]),$token[1],'NUMBER'];
                    }
                }
                break;
            case '>=';
            case 'ge':
                if ($bMismatchType) {
                    $result = [false,$token[1],'NUMBER'];
                }
                else {
                    $result = [($arg1[0] >= $arg2[0]),$token[1],'NUMBER'];

                }
                break;
            case '+':
                if ($bBothNumeric) {
                    $result = [($arg1[0] + $arg2[0]),$token[1],'NUMBER'];
                }
                else {
                    $result = [$arg1[0] . $arg2[0],$token[1],'STRING'];
                }
                break;
            case '-':
                if ($bBothNumeric) {
                    $result = [($arg1[0] - $arg2[0]),$token[1],'NUMBER'];
                }
                else {
                    $result = [NAN,$token[1],'NUMBER'];
                }
                break;
            case '*':
                if ($bBothNumeric) {
                    $result = [($arg1[0] * $arg2[0]),$token[1],'NUMBER'];
                }
                else {
                    $result = [NAN,$token[1],'NUMBER'];
                }
                break;
            case '/';
                if ($bBothNumeric) {
                    if ($arg2[0] == 0) {
                        $result = [NAN,$token[1],'NUMBER'];
                    }
                    else {
                        $result = [($arg1[0] / $arg2[0]),$token[1],'NUMBER'];
                    }
                }
                else {
                    $result = [NAN,$token[1],'NUMBER'];
                }
                break;
        }
        $this->RDP_StackPush($result);
        return true;
    }

    /**
     * Processes operations like +a, -b, !c
     * @param array $token
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluateUnary(array $token)
    {
        if (count($this->RDP_stack) < 1)
        {
            $this->RDP_AddError(gT("Unable to evaluate unary operator - no entries on stack"), $token);
            return false;
        }
        $arg1 = $this->RDP_StackPop();
        if (is_null($arg1))
        {
            $this->RDP_AddError(gT("Invalid value(s) on the stack"), $token);
            return false;
        }
        // TODO:  try to determine datatype?
        switch($token[0])
        {
            case '+':
                $result = [(+$arg1[0]),$token[1],'NUMBER'];
                break;
            case '-':
                $result = [(-$arg1[0]),$token[1],'NUMBER'];
                break;
            case '!';
                $result = [(!$arg1[0]),$token[1],'NUMBER'];
                break;
        }
        $this->RDP_StackPush($result);
        return true;
    }


    /**
     * Main entry function
     * @param <type> $expr
     * @param <type> $onlyparse - if true, then validate the syntax without computing an answer
     * @return boolean - true if success, false if any error occurred
     */

    public function RDP_Evaluate($expr, $onlyparse=false)
    {
        bP();
        $this->RDP_expr = $expr;
        $this->RDP_tokens = $this->RDP_Tokenize($expr);
        $this->RDP_count = count($this->RDP_tokens);
        $this->RDP_pos = -1; // starting position within array (first act will be to increment it)
        $this->RDP_errs = [];
        $this->RDP_onlyparse = $onlyparse;
        $this->RDP_stack = [];
        $this->RDP_evalStatus = false;
        $this->RDP_result = NULL;
        $this->varsUsed = [];
        $this->jsExpression = NULL;

        if ($this->HasSyntaxErrors()) {
            $result = false;
        } elseif ($this->RDP_EvaluateExpressions()) {
            if ($this->RDP_pos < $this->RDP_count)
            {
                $this->RDP_AddError(gT("Extra tokens found"), $this->RDP_tokens[$this->RDP_pos]);
                $result = false;
            } elseif (null === $this->RDP_result = $this->RDP_StackPop()) {
                $result = false;
            } elseif (count($this->RDP_stack) == 0) {
                $this->RDP_evalStatus = true;
                $result = true;
            } else {
                $this-RDP_AddError(gT("Unbalanced equation - values left on stack"),NULL);
                $result = false;
            }
        }
        else
        {
            $this->RDP_AddError(gT("Not a valid expression"),NULL);
            $result = false;
        }

        eP();
        return $result;
    }


    /**
     * Process "a op b" where op in (+,-,concatenate)
     * @return boolean - true if success, false if any error occurred
     */
    private function RDP_EvaluateAdditiveExpression()
    {
        if (!$this->RDP_EvaluateMultiplicativeExpression())
        {
            return false;
        }
        while (($this->RDP_pos + 1) < $this->RDP_count)
        {
            $token = $this->RDP_tokens[++$this->RDP_pos];
            if ($token[2] == 'BINARYOP')
            {
                switch ($token[0])
                {
                    case '+':
                    case '-';
                        if ($this->RDP_EvaluateMultiplicativeExpression())
                        {
                            if (!$this->RDP_EvaluateBinary($token))
                            {
                                return false;
                            }
                            // else continue;
                        }
                        else
                        {
                            return false;
                        }
                        break;
                    default:
                        --$this->RDP_pos;
                        return true;
                }
            }
            else
            {
                --$this->RDP_pos;
                return true;
            }
        }
        return true;
    }

    /**
     * Process a Constant (number of string), retrieve the value of a known variable, or process a function, returning result on the stack.
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluateConstantVarOrFunction()
    {
        if ($this->RDP_pos + 1 >= $this->RDP_count)
        {
             $this->RDP_AddError(gT("Poorly terminated expression - expected a constant or variable"), NULL);
             return false;
        }
        $token = $this->RDP_tokens[++$this->RDP_pos];
        switch ($token[2])
        {
            case 'NUMBER':
            case 'DQ_STRING':
            case 'SQ_STRING':
                $this->RDP_StackPush($token);
                return true;
                break;
            case 'WORD':
            case 'SGQA':
                if (($this->RDP_pos + 1) < $this->RDP_count and $this->RDP_tokens[($this->RDP_pos + 1)][2] == 'LP')
                {
                    return $this->RDP_EvaluateFunction();
                }
                else
                {
                    if ($this->RDP_isValidVariable($token[0]))
                    {
                        $this->varsUsed[] = $token[0];  // add this variable to list of those used in this equation
                        if (preg_match("/\.(gid|grelevance|gseq|jsName|mandatory|qid|qseq|question|readWrite|relevance|rowdivid|sgqa|type)$/",$token[0]))
                        {
                            $relStatus=1;   // static, so always relevant
                        }
                        else
                        {
                            $relStatus = $this->GetVarAttribute($token[0],'relevanceStatus',1);
                        }
                        if ($relStatus==1)
                        {
                            $argtype=($this->GetVarAttribute($token[0],'onlynum',0))?"NUMBER":"WORD";
                            $result = [$this->GetVarAttribute($token[0],NULL,''),$token[1],$argtype];
                        }
                        else
                        {
                            $result = [NULL,$token[1],'NUMBER'];   // was 0 instead of NULL
                        }
                        $this->RDP_StackPush($result);
                        return true;
                    }
                    else
                    {
                        $this->RDP_AddError(gT("Undefined variable"), $token);
                        return false;
                    }
                }
                break;
            case 'COMMA':
                --$this->RDP_pos;
                $this->RDP_AddError(gT("Should never  get to this line?"),$token);
                return false;
            default:
                return false;
                break;
        }
    }

    /**
     * Process "a == b", "a eq b", "a != b", "a ne b"
     * @return boolean - true if success, false if any error occurred
     */
    private function RDP_EvaluateEqualityExpression()
    {
        if (!$this->RDP_EvaluateRelationExpression())
        {
            return false;
        }
        while (($this->RDP_pos + 1) < $this->RDP_count)
        {
            $token = $this->RDP_tokens[++$this->RDP_pos];
            switch (strtolower($token[0]))
            {
                case '==':
                case 'eq':
                case '!=':
                case 'ne':
                    if ($this->RDP_EvaluateRelationExpression())
                    {
                        if (!$this->RDP_EvaluateBinary($token))
                        {
                            return false;
                        }
                        // else continue;
                    }
                    else
                    {
                        return false;
                    }
                    break;
                default:
                    --$this->RDP_pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process a single expression (e.g. without commas)
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluateExpression()
    {
        if ($this->RDP_pos + 2 < $this->RDP_count)
        {
            $token1 = $this->RDP_tokens[++$this->RDP_pos];
            $token2 = $this->RDP_tokens[++$this->RDP_pos];
            if ($token2[2] == 'ASSIGN')
            {
                if ($this->RDP_isValidVariable($token1[0]))
                {
                    $this->varsUsed[] = $token1[0];  // add this variable to list of those used in this equation
                    if ($this->GetVarAttribute($token1[0], 'readWrite', 'N') == 'Y')
                    {
                        $evalStatus = $this->RDP_EvaluateLogicalOrExpression();
                        if ($evalStatus)
                        {
                            $result = $this->RDP_StackPop();
                            if (!is_null($result))
                            {
                                $newResult = $token2;
                                $newResult[2] = 'NUMBER';
                                $newResult[0] = $this->RDP_SetVariableValue($token2[0], $token1[0], $result[0]);
                                $this->RDP_StackPush($newResult);
                            }
                            else
                            {
                                $evalStatus = false;
                            }
                        }
                        return $evalStatus;
                    }
                    else
                    {
                        $this->RDP_AddError(gT('The value of this variable can not be changed'), $token1);
                        return false;
                    }
                }
                else
                {
                    $this->RDP_AddError(gT('Only variables can be assigned values'), $token1);
                    return false;
                }
            }
            else
            {
                // not an assignment expression, so try something else
                $this->RDP_pos -= 2;
                return $this->RDP_EvaluateLogicalOrExpression();
            }
        }
        else
        {
            return $this->RDP_EvaluateLogicalOrExpression();
        }
    }

    /**
     * Process "expression [, expression]*
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluateExpressions()
    {
        $evalStatus = $this->RDP_EvaluateExpression();
        if (!$evalStatus)
        {
            return false;
        }

        while (++$this->RDP_pos < $this->RDP_count) {
            $token = $this->RDP_tokens[$this->RDP_pos];
            if ($token[2] == 'RP')
            {
                return true;    // presumbably the end of an expression
            }
            elseif ($token[2] == 'COMMA')
            {
                if ($this->RDP_EvaluateExpression())
                {
                    $secondResult = $this->RDP_StackPop();
                    $firstResult = $this->RDP_StackPop();
                    if (is_null($firstResult))
                    {
                        return false;
                    }
                    $this->RDP_StackPush($secondResult);
                    $evalStatus = true;
                }
                else
                {
                    return false;   // an error must have occurred
                }
            }
            else
            {
                $this->RDP_AddError(gT("Expected expressions separated by commas"),$token);
                $evalStatus = false;
                break;
            }
        }
        while (++$this->RDP_pos < $this->RDP_count)
        {
            $token = $this->RDP_tokens[$this->RDP_pos];
            $this->RDP_AddError(gT("Extra token found after expressions"),$token);
            $evalStatus = false;
        }
        return $evalStatus;
    }

    /**
     * Process a function call
     * @return boolean - true if success, false if any error occurred
     */
    private function RDP_EvaluateFunction()
    {
        $funcNameToken = $this->RDP_tokens[$this->RDP_pos]; // note that don't need to increment position for functions
        $funcName = $funcNameToken[0];
        if (!$this->RDP_isValidFunction($funcName))
        {
            $this->RDP_AddError(gT("Undefined function"), $funcNameToken);
            return false;
        }
        $token2 = $this->RDP_tokens[++$this->RDP_pos];
        if ($token2[2] != 'LP')
        {
            $this->RDP_AddError(gT("Expected left parentheses after function name"), $funcNameToken);
        }
        $params = [];  // will just store array of values, not tokens
        while ($this->RDP_pos + 1 < $this->RDP_count)
        {
            $token3 = $this->RDP_tokens[$this->RDP_pos + 1];
            if (count($params) > 0)
            {
                // should have COMMA or RP
                if ($token3[2] == 'COMMA')
                {
                    ++$this->RDP_pos;   // consume the token so can process next clause
                    if ($this->RDP_EvaluateExpression())
                    {
                        $value = $this->RDP_StackPop();
                        if (is_null($value))
                        {
                            return false;
                        }
                        $params[] = $value[0];
                        continue;
                    }
                    else
                    {
                        $this->RDP_AddError(gT("Extra comma found in function"), $token3);
                        return false;
                    }
                }
            }
            if ($token3[2] == 'RP')
            {
                ++$this->RDP_pos;   // consume the token so can process next clause
                return $this->RDP_RunFunction($funcNameToken,$params);
            }
            else
            {
                if ($this->RDP_EvaluateExpression())
                {
                    $value = $this->RDP_StackPop();
                    if (is_null($value))
                    {
                        return false;
                    }
                    $params[] = $value[0];
                    continue;
                }
                else
                {
                    return false;
                }
            }
        }
    }

    /**
     * Process "a && b" or "a and b"
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluateLogicalAndExpression()
    {
        if (!$this->RDP_EvaluateEqualityExpression())
        {
            return false;
        }
        while (($this->RDP_pos + 1) < $this->RDP_count)
        {
            $token = $this->RDP_tokens[++$this->RDP_pos];
            switch (strtolower($token[0]))
            {
                case '&&':
                case 'and':
                    if ($this->RDP_EvaluateEqualityExpression())
                    {
                        if (!$this->RDP_EvaluateBinary($token))
                        {
                            return false;
                        }
                        // else continue
                    }
                    else
                    {
                        return false;   // an error must have occurred
                    }
                    break;
                default:
                    --$this->RDP_pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process "a || b" or "a or b"
     * @return boolean - true if success, false if any error occurred
     */
    private function RDP_EvaluateLogicalOrExpression()
    {
        if (!$this->RDP_EvaluateLogicalAndExpression())
        {
            return false;
        }
        while (($this->RDP_pos + 1) < $this->RDP_count)
        {
            $token = $this->RDP_tokens[++$this->RDP_pos];
            switch (strtolower($token[0]))
            {
                case '||':
                case 'or':
                    if ($this->RDP_EvaluateLogicalAndExpression())
                    {
                        if (!$this->RDP_EvaluateBinary($token))
                        {
                            return false;
                        }
                        // else  continue
                    }
                    else
                    {
                        // an error must have occurred
                        return false;
                    }
                    break;
                default:
                    // no more expressions being  ORed together, so continue parsing
                    --$this->RDP_pos;
                    return true;
            }
        }
        // no more tokens to parse
        return true;
    }

    /**
     * Process "a op b" where op in (*,/)
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluateMultiplicativeExpression()
    {
        if (!$this->RDP_EvaluateUnaryExpression())
        {
            return  false;
        }
        while (($this->RDP_pos + 1) < $this->RDP_count)
        {
            $token = $this->RDP_tokens[++$this->RDP_pos];
            if ($token[2] == 'BINARYOP')
            {
                switch ($token[0])
                {
                    case '*':
                    case '/';
                        if ($this->RDP_EvaluateUnaryExpression())
                        {
                            if (!$this->RDP_EvaluateBinary($token))
                            {
                                return false;
                            }
                            // else  continue
                        }
                        else
                        {
                            // an error must have occurred
                            return false;
                        }
                        break;
                        break;
                    default:
                        --$this->RDP_pos;
                        return true;
                }
            }
            else
            {
                --$this->RDP_pos;
                return true;
            }
        }
        return true;
    }

    /**
     * Process expressions including functions and parenthesized blocks
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluatePrimaryExpression()
    {
        if (($this->RDP_pos + 1) >= $this->RDP_count) {
            $this->RDP_AddError(gT("Poorly terminated expression - expected a constant or variable"), NULL);
            return false;
        }
        $token = $this->RDP_tokens[++$this->RDP_pos];
        if ($token[2] == 'LP')
        {
            if (!$this->RDP_EvaluateExpressions())
            {
                return false;
            }
            $token = $this->RDP_tokens[$this->RDP_pos];
            if ($token[2] == 'RP')
            {
                return true;
            }
            else
            {
                $this->RDP_AddError(gT("Expected right parentheses"), $token);
                return false;
            }
        }
        else
        {
            --$this->RDP_pos;
            return $this->RDP_EvaluateConstantVarOrFunction();
        }
    }

    /**
     * Process "a op b" where op in (lt, gt, le, ge, <, >, <=, >=)
     * @return boolean - true if success, false if any error occurred
     */
    private function RDP_EvaluateRelationExpression()
    {
        if (!$this->RDP_EvaluateAdditiveExpression())
        {
            return false;
        }
        while (($this->RDP_pos + 1) < $this->RDP_count)
        {
            $token = $this->RDP_tokens[++$this->RDP_pos];
            switch (strtolower($token[0]))
            {
                case '<':
                case 'lt':
                case '<=';
                case 'le':
                case '>':
                case 'gt':
                case '>=';
                case 'ge':
                    if ($this->RDP_EvaluateAdditiveExpression())
                    {
                        if (!$this->RDP_EvaluateBinary($token))
                        {
                            return false;
                        }
                        // else  continue
                    }
                    else
                    {
                        // an error must have occurred
                        return false;
                    }
                    break;
                default:
                    --$this->RDP_pos;
                    return true;
            }
        }
        return true;
    }

    /**
     * Process "op a" where op in (+,-,!)
     * @return boolean - true if success, false if any error occurred
     */

    private function RDP_EvaluateUnaryExpression()
    {
        if (($this->RDP_pos + 1) >= $this->RDP_count) {
            $this->RDP_AddError(gT("Poorly terminated expression - expected a constant or variable"), NULL);
            return false;
        }
        $token = $this->RDP_tokens[++$this->RDP_pos];
        if ($token[2] == 'NOT' || $token[2] == 'BINARYOP')
        {
            switch ($token[0])
            {
                case '+':
                case '-':
                case '!':
                    if (!$this->RDP_EvaluatePrimaryExpression())
                    {
                        return false;
                    }
                    return $this->RDP_EvaluateUnary($token);
                    break;
                default:
                    --$this->RDP_pos;
                    return $this->RDP_EvaluatePrimaryExpression();
            }
        }
        else
        {
            --$this->RDP_pos;
            return $this->RDP_EvaluatePrimaryExpression();
        }
    }



    /**
     * Return the result of evaluating the equation - NULL if  error
     * @return mixed
     */
    public function GetResult()
    {
        return $this->RDP_result[0];
    }

    /**
     * Return an array of errors
     * @return array
     */
    public function GetErrors()
    {
        return $this->RDP_errs;
    }

    /**
     * Converts the given expression to javascript.
     * @param string $expression
     * @return string the JavaScript expresssion
     */
    public function getJavascript($expression)
    {
        if (empty($expression)) {
            return true;
        }
        $tokens = $this->RDP_Tokenize($this->ExpandThisVar($expression));

        $varsUsed = [];
        $stringParts = [];
        $numTokens = count($tokens);
        for ($i=0;$i<$numTokens;++$i)
        {
            $token = $tokens[$i];
            // When do these need to be quoted?

            switch ($token[2])
            {
                case 'DQ_STRING':
                    $stringParts[] = '"' . $token[0] . '"'; // htmlspecialchars($token[0],ENT_QUOTES,'UTF-8',false) . "'";
                    break;
                case 'SQ_STRING':
                    $stringParts[] = "'{$token[0]}'";
                    break;
                case 'SGQA':
                case 'WORD':
                    if ($i+1<$numTokens && $tokens[$i+1][2] == 'LP')
                    {
                        // then word is a function name
                        $funcInfo = $this->RDP_ValidFunctions[$token[0]];
                        if ($funcInfo[1] == 'NA')
                        {
                            return '';  // to indicate that this is trying to use a undefined function.  Need more graceful solution
                        }
                        $stringParts[] = $funcInfo[1];  // the PHP function name
                    }
                    elseif ($i+1<$numTokens && $tokens[$i+1][2] == 'ASSIGN')
                    {
                        /**
                         * @todo Implement this properly, remove dependency on getVarAttribute.
                         */
                        throw new \Exception("Not yet supported");
//                        $jsName = $this->GetVarAttribute($token[0],'jsName','');
//                        $stringParts[] = "document.getElementById('" . $jsName . "').value";
//                        if ($tokens[$i+1][0] == '+=')
//                        {
//                            // Javascript does concatenation unless both left and right side are numbers, so refactor the equation
//                            $varName = $this->GetVarAttribute($token[0],'varName',$token[0]);
//                            $stringParts[] = " = EM.val('" . $varName . "') + ";
//                            ++$i;
//                        }
                    } else {
                        $varsUsed[] = $token[0];
                        $stringParts[] = "EM.val('{$token[0]}')";
                    }
                    break;
                case 'LP':
                case 'RP':
                    $stringParts[] = $token[0];
                    break;
                case 'NUMBER':
                    $stringParts[] = is_numeric($token[0]) ? $token[0] : ("'" . $token[0] . "'");
                    break;
                case 'COMMA':
                    $stringParts[] = $token[0] . ' ';
                    break;
                default:
                    // don't need to check type of $token[2] here since already handling SQ_STRING and DQ_STRING above
                    switch (strtolower($token[0]))
                    {
                        case 'and': $stringParts[] = ' && '; break;
                        case 'or':  $stringParts[] = ' || '; break;
                        case 'lt':  $stringParts[] = ' < '; break;
                        case 'le':  $stringParts[] = ' <= '; break;
                        case 'gt':  $stringParts[] = ' > '; break;
                        case 'ge':  $stringParts[] = ' >= '; break;
                        case 'eq':  case '==': $stringParts[] = ' == '; break;
                        case 'ne':  case '!=': $stringParts[] = ' != '; break;
                        default:    $stringParts[] = ' ' . $token[0] . ' '; break;
                    }
                    break;
            }
        }
        // for each variable that does not have a default value, add clause to throw error if any of them are NA
        $mainClause = implode('', $stringParts);
        if ($varsUsed != '')
        {
            $result = "LEMif(LEManyNA(" . json_encode($varsUsed) . "), null, " . $mainClause . ")";
        }
        else
        {
            $result = '(' . $mainClause . ')';
        }
        return $result;
    }

    /**
     * Returns the most recent PrettyPrint string generated by sProcessStringContainingExpressions
     */
    public function GetLastPrettyPrintExpression()
    {
        return $this->prettyPrintSource;
    }

    /**
     * This is only used when there are no needed substitutions
     * @param <type> $expr
     */
    public function SetPrettyPrintSource($expr)
    {
        $this->prettyPrintSource = $expr;
    }


    /**
     * Get information about the variable, including JavaScript name, read-write status, and whether set on current page.
     * @param <type> $varname
     * @return <type>
     */
    private function GetVarAttribute($name, $attr = null, $default = null)
    {
        if (isset($this->variableGetter)) {
            $getter = $this->variableGetter;
            return $getter($name, $attr, $default, $this->groupSeq, $this->questionSeq);
        }

    }

    /**
     * Return array of the list of variables used  in the equation
     * @return array
     */
    public function GetVarsUsed()
    {
        return array_unique($this->varsUsed);
    }

    /**
     * Return true if there were syntax or processing errors
     * @return boolean
     */
    public function HasErrors()
    {
        return (count($this->RDP_errs) > 0);
    }

    /**
     * Return true if there are syntax errors
     * @return boolean
     */
    private function HasSyntaxErrors()
    {
        // check for bad tokens
        // check for unmatched parentheses
        // check for undefined variables
        // check for undefined functions (but can't easily check allowable # elements?)

        $nesting = 0;

        for ($i=0;$i<$this->RDP_count;++$i)
        {
            $token = $this->RDP_tokens[$i];
            switch ($token[2])
            {
                case 'LP':
                    ++$nesting;
                    break;
                case 'RP':
                    --$nesting;
                    if ($nesting < 0)
                    {
                        $this->RDP_AddError(gT("Extra right parentheses detected"), $token);
                    }
                    break;
                case 'WORD':
                case 'SGQA':
                    if ($i+1 < $this->RDP_count and $this->RDP_tokens[$i+1][2] == 'LP')
                    {
                        if (!$this->RDP_isValidFunction($token[0]))
                        {
                            $this->RDP_AddError(gT("Undefined function"), $token);
                        }
                    }
                    else
                    {
                        if (!($this->RDP_isValidVariable($token[0])))
                        {
                            $this->RDP_AddError(gT("Undefined variable"), $token);
                        }
                    }
                    break;
                case 'OTHER':
                    $this->RDP_AddError(gT("Unsupported syntax"), $token);
                    break;
                default:
                    break;
            }
        }
        if ($nesting != 0)
        {
            $this->RDP_AddError(sprintf(gT("Missing %s closing right parentheses"),$nesting),NULL);
        }
        return (count($this->RDP_errs) > 0);
    }

    /**
     * Return true if the function name is registered
     * @param <type> $name
     * @return boolean
     */

    private function RDP_isValidFunction($name)
    {
        return array_key_exists($name,$this->RDP_ValidFunctions);
    }

    /**
     * Return true if the variable name is registered
     * @param <type> $name
     * @return boolean
     */
    private function RDP_isValidVariable($name)
    {
        $varName = preg_replace("/^(?:INSERTANS:)?(.*?)(?:\.(?:" . self::$RDP_regex_var_attr . "))?$/", "$1", $name);
        $getter = $this->variableGetter;
        $result = true;
        try {
            $getter($varName, null, null, null, null);
        } catch (\Exception $e) {
            $result = false;
        }
        return $result;
    }


    /**
     * Process an expression and return its boolean value
     * @param <type> $expr
     * @param <type> $groupSeq - needed to determine whether using variables before they are declared
     * @param <type> $questionSeq - needed to determine whether using variables before they are declared
     * @return boolean
     */
    public function ProcessBooleanExpression($expr,$groupSeq=-1,$questionSeq=-1)
    {
        $this->groupSeq = $groupSeq;
        $this->questionSeq = $questionSeq;

        $expr = $this->ExpandThisVar($expr);
        $status = $this->RDP_Evaluate($expr);
        if (!$status) {
            return false;    // if there are errors in the expression, hide it?
        }
        $result = $this->GetResult();
        if (is_null($result)) {
            return false;    // if there are errors in the expression, hide it?
        }

        // Check whether any variables are irrelevant - making this comparable to JavaScript which uses LEManyNA(varlist) to do the same thing
        foreach ($this->GetVarsUsed() as $var)    // this function wants to see the NAOK suffix
        {
            if (!preg_match("/^.*\.(NAOK|relevanceStatus)$/", $var))
            {
                if (!LimeExpressionManager::GetVarAttribute($var,'relevanceStatus',false,$groupSeq,$questionSeq))
                {
                    return false;
                }
            }
        }
        return (boolean) $result;
    }


    /**
     * Process multiple substitution iterations of a full string, containing multiple expressions delimited by {}, return a consolidated string
     * @param <type> $src
     * @param int $numRecursionLevels
     * @param int $whichPrettyPrintIteration
     * @param int $groupSeq
     * @param int $questionSeq
     * @return mixed|string <type>
     * @throws Exception
     * @internal param bool $staticReplacement
     */

    public function sProcessStringContainingExpressions(
        $src,
        $numRecursionLevels = 1,
        $whichPrettyPrintIteration = 1,
        $groupSeq = -1,
        $questionSeq = -1
    )
    {
        bP();
        $this->allVarsUsed = [];
        $this->questionSeq = $questionSeq;
        $this->groupSeq = $groupSeq;
        $result = $src;
        $prettyPrint = '';
        $errors = [];

        for($i=1;$i<=$numRecursionLevels;++$i)
        {
            $result = $this->sProcessStringContainingExpressionsHelper($result);
            if ($i == $whichPrettyPrintIteration)
            {
                $prettyPrint = $this->prettyPrintSource;
            }
            $errors = array_merge($errors, $this->RDP_errs);
        }
        $this->prettyPrintSource = $prettyPrint;    // ensure that if doing recursive substition, can get original source to pretty print
        $this->RDP_errs = $errors;
        $result = str_replace(['\{', '\}'], ['{', '}'], $result);
        eP();
        return $result;
    }

    /**
     * Process one substitution iteration of a full string, containing multiple expressions delimited by {}, return a consolidated string
     * @param <type> $src
     * @param <type> $questionNum - used to generate substitution <span>s that indicate to which question they belong
     * @return <type>
     */

    public function sProcessStringContainingExpressionsHelper($src)
    {
        bP();
        // tokenize string by the {} pattern, properly dealing with strings in quotations, and escaped curly brace values
        $stringParts = $this->asSplitStringOnExpressions($src);
        $resolvedParts = [];
        $prettyPrintParts = [];
        $allErrors= [];

        foreach ($stringParts as $stringPart)
        {
            switch($stringPart[2]) {
                case 'STRING':
                    $resolvedParts[] =  $stringPart[0];
                    $prettyPrintParts[] = $stringPart[0];
                    break;
                case 'EXPRESSION':
                    $expr = $this->ExpandThisVar(substr($stringPart[0], 1, -1));
                    $resolvedParts[] = $this->RDP_Evaluate($expr) ? $this->GetResult() : $expr;

                    break;
                default:
                    throw new \Exception("Unknown token: {$stringPart[2]}");
            }
        }
        $result = implode('', $resolvedParts);
        eP();
        return $result;    // recurse in case there are nested ones, avoiding infinite loops?
    }

    /**
     * If the equation contains reference to this, expand to comma separated list if needed.
     * @param type $eqn
     */
    protected function ExpandThisVar($src)
    {
        bP();
        $splitter = '(?:\b(?:self|that))(?:\.(?:[A-Z0-9_]+))*';
        $parts = preg_split("/(" . $splitter . ")/i",$src,-1,(PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE));
        $result = '';
        foreach ($parts as $part)
        {
            if (preg_match("/" . $splitter . "/",$part))
            {
                $result .= $this->GetAllVarNamesForQ($part);
            }
            else
            {
                $result .= $part;
            }
        }
        eP();
        return $result;
    }

    /**
     * Expand "self.suffix" and "that.qcode.suffix" into canonical list of variable names
     * @param type $qseq
     * @param type $varName
     */
    protected function GetAllVarNamesForQ($varName)
    {
        static $requestCache = [];
        bP();

        if (!isset($requestCache[$varName])) {

            $parts = explode('.', $varName);
            $qroot = '';
            $suffix = '';
            $sqpatts = [];
            $nosqpatts = [];
            $sqpatt = '';
            $nosqpatt = '';

            if ($parts[0] == 'self') {
                $type = 'self';
            } else {
                $type = 'that';
                array_shift($parts);
                if (isset($parts[0])) {
                    $qroot = $parts[0];
                } else {
                    return $varName;
                }
            }
            array_shift($parts);

            if (count($parts) > 0) {
                if (preg_match('/^' . self::$RDP_regex_var_attr . '$/', $parts[count($parts) - 1])) {
                    $suffix = '.' . $parts[count($parts) - 1];
                    array_pop($parts);
                }
            }

            $question = $this->getQuestionByCode($qroot);

            foreach ($parts as $part) {
                if ($part == 'nocomments') {
                    $comments = false;
                } else {
                    if ($part == 'comments') {
                        $comments = true;
                    } else {
                        if (preg_match('/^sq_.+$/', $part)) {
                            $sqpatts[] = substr($part, 3);
                        } else {
                            if (preg_match('/^nosq_.+$/', $part)) {
                                $nosqpatts[] = substr($part, 5);
                            } else {
                                return $varName;    // invalid
                            }
                        }
                    }
                }
            }
            $sqpatt = implode('|', $sqpatts);
            $nosqpatt = implode('|', $nosqpatts);
            $vars = [];
            foreach ($question->fields as $field) {
                if (isset($comments)) {
                    if (($comments && !preg_match('/comment$/', $field->name))
                        || (!$comments && preg_match('/comment$/', $field->name))
                    ) {
                        continue;
                    }
                }

                $ext = substr($field->name, strlen($question->sgqa));

                if ($sqpatt != '') {
                    if (!preg_match('/' . $sqpatt . '/', $ext)) {
                        continue;
                    }
                }
                if ($nosqpatt != '') {
                    if (preg_match('/' . $nosqpatt . '/', $ext)) {
                        continue;
                    }
                }

                $vars[] = $field->code . $suffix;
            }
            if (count($vars) > 0) {
                $requestCache[$varName] = implode(',', $vars);
            } else {
                $requestCache[$varName] = $varName;    // invalid
            }
        }
        eP();
        return $requestCache[$varName];
    }

    /**
     * Run a registered function
     * Some PHP functions require specific data types - those can be cast here.
     * @param <type> $funcNameToken
     * @param <type> $params
     * @return boolean
     */
    private function RDP_RunFunction($funcNameToken,$params)
    {
        $name = $funcNameToken[0];
        if (!$this->RDP_isValidFunction($name))
        {
            return false;
        }
        $func = $this->RDP_ValidFunctions[$name];
        $funcName = $func[0];
        $numArgs = count($params);
        $result=1;  // default value for $this->RDP_onlyparse
        if (function_exists($funcName)) {
            $numArgsAllowed = array_slice($func, 5);    // get array of allowable argument counts from end of $func
            $argsPassed = is_array($params) ? count($params) : 0;

            // for unlimited #  parameters (any value less than 0).
            try
            {
                if ($numArgsAllowed[0] < 0) {
                    $minArgs = abs($numArgsAllowed[0] + 1); // so if value is -2, means that requires at least one argument
                    if ($argsPassed < $minArgs)
                    {
                        $this->RDP_AddError(sprintf(Yii::t("Function must have at least %s argument|Function must have at least %s arguments",$minArgs), $minArgs), $funcNameToken);
                        return false;
                    }
                    if (!$this->RDP_onlyparse) {
                        switch($funcName) {
                            case 'sprintf':
                                // PHP doesn't let you pass array of parameters to function, so must use call_user_func_array
                                $result = call_user_func_array('sprintf',$params);
                                break;
                            default:
                                $result = $funcName($params);
                                break;
                        }
                    }
                // Call  function with the params passed
                } elseif (in_array($argsPassed, $numArgsAllowed)) {
                    switch ($argsPassed) {
                    case 0:
                        if (!$this->RDP_onlyparse) {
                            $result = $funcName();
                        }
                        break;
                    case 1:
                        if (!$this->RDP_onlyparse) {
                            switch($funcName) {
                                case 'acos':
                                case 'asin':
                                case 'atan':
                                case 'cos':
                                case 'exp':
                                case 'is_nan':
                                case 'sin':
                                case 'sqrt':
                                case 'tan':
                                    if (is_numeric($params[0]))
                                    {
                                        $result = $funcName(floatval($params[0]));
                                    }
                                    else
                                    {
                                        $result = NAN;
                                    }
                                    break;
                                default:
                                    $result = $funcName($params[0]);
                                    break;
                            }
                        }
                        break;
                    case 2:
                        if (!$this->RDP_onlyparse) {
                            switch($funcName) {
                                case 'atan2':
                                    if (is_numeric($params[0]) && is_numeric($params[1]))
                                    {
                                        $result = $funcName(floatval($params[0]),floatval($params[1]));
                                    }
                                    else
                                    {
                                        $result = NAN;
                                    }
                                    break;
                                default:
                                    $result = $funcName($params[0], $params[1]);
                                     break;
                            }
                        }
                        break;
                    case 3:
                        if (!$this->RDP_onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2]);
                        }
                        break;
                    case 4:
                        if (!$this->RDP_onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2], $params[3]);
                        }
                        break;
                    case 5:
                        if (!$this->RDP_onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2], $params[3], $params[4]);
                        }
                        break;
                    case 6:
                        if (!$this->RDP_onlyparse) {
                            $result = $funcName($params[0], $params[1], $params[2], $params[3], $params[4], $params[5]);
                        }
                        break;
                    default:
                        $this->RDP_AddError(sprintf(gT("Unsupported number of arguments: %s", $argsPassed)), $funcNameToken);
                        return false;
                    }

                } else {
                    $this->RDP_AddError(sprintf(gT("Function does not support %s arguments"), $argsPassed).' '
                            . sprintf(gT("Function supports this many arguments, where -1=unlimited: %s"), implode(',', $numArgsAllowed)), $funcNameToken);
                    return false;
                }
            }
            catch (Exception $e)
            {
                $this->RDP_AddError($e->getMessage(),$funcNameToken);
                return false;
            }
            $token = [$result,$funcNameToken[1],'NUMBER'];
            $this->RDP_StackPush($token);
            return true;
        }
    }

    /**
     * Add user functions to array of allowable functions within the equation.
     * $functions is an array of key to value mappings like this:
     * See $this->RDP_ValidFunctions for examples of the syntax
     * @param array $functions
     */

    public function RegisterFunctions(array $functions) {
        $this->RDP_ValidFunctions= array_merge($this->RDP_ValidFunctions, $functions);
    }

    /**
     * Set the value of a registered variable
     * @param $op - the operator (=,*=,/=,+=,-=)
     * @param <type> $name
     * @param <type> $value
     */
    private function RDP_SetVariableValue($op,$name,$value)
    {
        if ($this->RDP_onlyparse)
        {
            return 1;
        }
        return LimeExpressionManager::SetVariableValue($op, $name, $value);
    }

  /**
     * Split a soure string into STRING vs. EXPRESSION, where the latter is surrounded by unescaped curly braces.
     * This verson properly handles nested curly braces and curly braces within strings within curly braces - both of which are needed to better support JavaScript
     * Users still need to add a space or carriage return after opening braces (and ideally before closing braces too) to avoid  having them treated as expressions.
     * @param <type> $src
     * @return string
     */
    public function asSplitStringOnExpressions($src)
    {
        bP();
        $parts = preg_split($this->RDP_ExpressionRegex,$src,-1,(PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE));


        $count = count($parts);
        $tokens = [];
        $inSQString=false;
        $inDQString=false;
        $curlyDepth=0;
        $thistoken= [];
        $offset=0;
        for ($j=0;$j<$count;++$j)
        {
            switch($parts[$j])
            {
                case '{':
                    if ($j < ($count-1) && preg_match('/\s|\n|\r/',substr($parts[$j+1],0,1)))
                    {
                        // don't count this as an expression if the opening brace is followed by whitespace
                        $thistoken[] = '{';
                        $thistoken[] = $parts[++$j];
                    }
                    else if ($inDQString || $inSQString)
                    {
                        // just push the curly brace
                        $thistoken[] = '{';
                    }
                    else if ($curlyDepth>0)
                    {
                        // a nested curly brace - just push it
                        $thistoken[] = '{';
                        ++$curlyDepth;
                    }
                    else
                    {
                        // then starting an expression - save the out-of-expression string
                        if (count($thistoken) > 0)
                        {
                            $_token = implode('',$thistoken);
                            $tokens[] = [
                                $_token,
                                $offset,
                                'STRING'
                            ];
                            $offset += strlen($_token);
                        }
                        $curlyDepth=1;
                        $thistoken = [];
                        $thistoken[] = '{';
                    }
                    break;
                case '}':
                    // don't count this as an expression if the closing brace is preceded by whitespace
                    if ($j > 0 && preg_match('/\s|\n|\r/',substr($parts[$j-1],-1,1)))
                    {
                        $thistoken[] = '}';
                    }
                    else if ($curlyDepth==0)
                    {
                        // just push the token
                        $thistoken[] = '}';
                    }
                    else
                    {
                        if ($inSQString || $inDQString)
                        {
                            // just push the token
                            $thistoken[] = '}';
                        }
                        else
                        {
                            --$curlyDepth;
                            if ($curlyDepth==0)
                            {
                                // then closing expression
                                $thistoken[] = '}';
                                $_token = implode('',$thistoken);
                                $tokens[] = [
                                    $_token,
                                    $offset,
                                    'EXPRESSION'
                                ];
                                $offset += strlen($_token);
                                $thistoken= [];
                            }
                            else
                            {
                                // just push the token
                                $thistoken[] = '}';
                            }
                        }
                    }
                    break;
                case '\'':
                    $thistoken[] = '\'';
                    if ($curlyDepth==0)
                    {
                        // only counts as part of a string if it is already within an expression
                    }
                    else
                    {
                        if ($inDQString)
                        {
                            // then just push the single quote
                        }
                        else
                        {
                            if ($inSQString) {
                                $inSQString=false;  // finishing a single-quoted string
                            }
                            else {
                                $inSQString=true;   // starting a single-quoted string
                            }
                        }
                    }
                    break;
                case '"':
                    $thistoken[] = '"';
                    if ($curlyDepth==0)
                    {
                        // only counts as part of a string if it is already within an expression
                    }
                    else
                    {
                        if ($inSQString)
                        {
                            // then just push the double quote
                        }
                        else
                        {
                            if ($inDQString) {
                                $inDQString=false;  // finishing a double-quoted string
                            }
                            else {
                                $inDQString=true;   // starting a double-quoted string
                            }
                        }
                    }
                    break;
                case '\\':
                    if ($j < ($count-1)) {
                        $thistoken[] = $parts[$j++];
                        $thistoken[] = $parts[$j];
                    }
                    break;
                default:
                    $thistoken[] = $parts[$j];
                    break;
            }
        }
        if (count($thistoken) > 0)
        {
            $tokens[] = [
                implode('',$thistoken),
                $offset,
                'STRING',
            ];
        }
        eP();
        return $tokens;
    }

    /**
     * Pop a value token off of the stack
     * @return token
     */

    private function RDP_StackPop()
    {
        if (count($this->RDP_stack) > 0)
        {
            return array_pop($this->RDP_stack);
        }
        else
        {
            $this->RDP_AddError(gT("Tried to pop value off of empty stack"), NULL);
            return NULL;
        }
    }

    /**
     * Stack only holds values (number, string), not operators
     * @param array $token
     */

    private function RDP_StackPush(array $token)
    {
        if ($this->RDP_onlyparse)
        {
            // If only parsing, still want to validate syntax, so use "1" for all variables
            switch($token[2])
            {
                case 'DQ_STRING':
                case 'SQ_STRING':
                    $this->RDP_stack[] = [1,$token[1],$token[2]];
                    break;
                case 'NUMBER':
                default:
                    $this->RDP_stack[] = [1,$token[1],'NUMBER'];
                    break;
            }
        }
        else
        {
            $this->RDP_stack[] = $token;
        }
    }

    /**
    * Public call of RDP_Tokenize
    *
    * @param string $sSource : the string to tokenize
    * @param bool $bOnEdit : on edition, actually don't remove space
    * @return array
    */
    public function Tokenize($sSource,$bOnEdit)
    {
        return $this->RDP_Tokenize($sSource,$bOnEdit);
    }

    /**
    * Split the source string into tokens, removing whitespace, and categorizing them by type.
    *
    * @param string $sSource : the string to tokenize
    * @param bool $bOnEdit : on edition, actually don't remove space
    * @return array
    */
    private function RDP_Tokenize($sSource,$bOnEdit=false)
    {
        // $aInitTokens = array of tokens from equation, showing value and offset position.  Will include SPACE.
        if($bOnEdit)
            $aInitTokens = preg_split($this->RDP_TokenizerRegex,$sSource,-1,(PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));
        else
            $aInitTokens = preg_split($this->RDP_TokenizerRegex,$sSource,-1,(PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE));

        // $aTokens = array of tokens from equation, showing value, offsete position, and type.  Will not contain SPACE if !$bOnEdit, but will contain OTHER
        $aTokens = [];
        // Add token_type to $tokens:  For each token, test each categorization in order - first match will be the best.
        for ($j=0;$j<count($aInitTokens);++$j)
        {
            for ($i=0;$i<count($this->RDP_CategorizeTokensRegex);++$i)
            {
                $sToken = $aInitTokens[$j][0];
                if (preg_match($this->RDP_CategorizeTokensRegex[$i],$sToken))
                {
                    if ($this->RDP_TokenType[$i] !== 'SPACE' || $bOnEdit) {
                        $aInitTokens[$j][2] = $this->RDP_TokenType[$i];
                        if ($this->RDP_TokenType[$i] == 'DQ_STRING' || $this->RDP_TokenType[$i] == 'SQ_STRING')
                        {
                            // remove outside quotes
                            $sUnquotedToken = str_replace(['\"',"\'","\\\\"], ['"',"'",'\\'],substr($sToken,1,-1));
                            $aInitTokens[$j][0] = $sUnquotedToken;
                        }
                        $aTokens[] = $aInitTokens[$j];   // get first matching non-SPACE token type and push onto $tokens array
                    }
                    break;  // only get first matching token type
                }
            }
        }
        return $aTokens;
    }


    /**
     * Show a table of allowable Expression Manager functions
     * @return string
     */

    static function ShowAllowableFunctions()
    {
        $em = new ExpressionManager();
        $output = "<h3>Functions Available within Expression Manager</h3>\n";
        $output .= "<table border='1'><tr><th>Function</th><th>Meaning</th><th>Syntax</th><th>Reference</th></tr>\n";
        foreach ($em->RDP_ValidFunctions as $name => $func) {
            $output .= "<tr><td>" . $name . "</td><td>" . $func[2] . "</td><td>" . $func[3] . "</td><td><a href='" . $func[4] . "'>" . $func[4] . "</a>&nbsp;</td></tr>\n";
        }
        $output .= "</table>\n";
        return $output;
    }


    /**
     * Create dynamic replacements.
     * @param string $text
     * @return string
     */
    public function createDynamicReplacements($text)
    {
        $parts = $this->asSplitStringOnExpressions($text);
        $result = '';

        foreach ($parts as $part) {
            switch ($part[2]) {
                case 'STRING':
                    $result .= $part[0];
                    break;
                case 'EXPRESSION':
                    if ($this->RDP_Evaluate(substr($part[0], 1, -1))) {
                        $value = $this->GetResult();
                    } else {

                        $value = '';
                    }
                    $result .= TbHtml::tag('span', [
                        'data-expression' => $this->getJavascript(substr($part[0], 1, -1))
                    ], $value);
            }
        }

        return $result;

    }
}

/**
 * Used by usort() to order Error tokens by their position within the string
 * This must be outside of the class in order to work in PHP 5.2
 * @param <type> $a
 * @param <type> $b
 * @return <type>
 */
function cmpErrorTokens($a, $b)
{
    if (is_null($a[1])) {
        if (is_null($b[1])) {
            return 0;
        }
        return 1;
    }
    if (is_null($b[1])) {
        return -1;
    }
    if ($a[1][1] == $b[1][1]) {
        return 0;
    }
    return ($a[1][1] < $b[1][1]) ? -1 : 1;
}

/**
 * Count the number of answered questions (non-empty)
 * @param <type> $args
 * @return int
 */
function exprmgr_count($args)
{
    $j=0;    // keep track of how many non-null values seen
    foreach ($args as $arg)
    {
        if ($arg != '') {
            ++$j;
        }
    }
    return $j;
}

/**
 * Count the number of answered questions (non-empty) which match the first argument
 * @param <type> $args
 * @return int
 */
function exprmgr_countif($args)
{
    $j=0;    // keep track of how many non-null values seen
    $match = array_shift($args);
    foreach ($args as $arg)
    {
        if ($arg == $match) {
            ++$j;
        }
    }
    return $j;
}

/**
 * Count the number of answered questions (non-empty) which meet the criteria (arg op value)
 * @param <type> $args
 * @return int
 */
function exprmgr_countifop($args)
{
    $j=0;
    $op = array_shift($args);
    $value = array_shift($args);
    foreach ($args as $arg)
    {
        switch($op)
        {
            case '==':  case 'eq': if ($arg == $value) { ++$j; } break;
            case '>=':  case 'ge': if ($arg >= $value) { ++$j; } break;
            case '>':   case 'gt': if ($arg > $value) { ++$j; } break;
            case '<=':  case 'le': if ($arg <= $value) { ++$j; } break;
            case '<':   case 'lt': if ($arg < $value) { ++$j; } break;
            case '!=':  case 'ne': if ($arg != $value) { ++$j; } break;
            case 'RX':
                try {
                    if (@preg_match($value, $arg))
                    {
                        ++$j;
                    }
                }
                catch (Exception $e) { }
                break;
        }
    }
    return $j;
}
/**
 * Find position of first occurrence of unicode string in a unicode string, case insensitive
 * @param string $haystack : checked string
 * @param string $needle : string to find
 * @param $offset : offset
 * @return int|false : position or false if not found
 */
function exprmgr_stripos($haystack , $needle ,$offset=0)
{
    if($offset > mb_strlen($haystack))
        return false;
    return mb_stripos($haystack , $needle ,$offset,'UTF-8');
}
/**
 * Finds first occurrence of a unicode string within another, case-insensitive
 * @param string $haystack : checked string
 * @param string $needle : string to find
 * @param boolean $before_needle : portion to return
 * @return string|false 
 */
function exprmgr_stristr($haystack,$needle,$before_needle=false)
{
    return mb_stristr($haystack,$needle,$before_needle,'UTF-8');
}
/**
 * Get unicode string length 
 * @param string $string
 * @return int
 */
function exprmgr_strlen($string)
{
    return mb_strlen ($string,'UTF-8');
}
/**
 * Find position of first occurrence of unicode string in a unicode string
 * @param string $haystack : checked string
 * @param string $needle : string to find
 * @param $offset : offset
 * @return int|false : position or false if not found
 */
function exprmgr_strpos($haystack , $needle ,$offset=0)
{
    if($offset > mb_strlen($haystack))
        return false;
    return mb_strpos($haystack , $needle ,$offset,'UTF-8');
}
/**
 * Finds first occurrence of a unicode string within another
 * @param string $haystack : checked string
 * @param string $needle : string to find
 * @param boolean $before_needle : portion to return
 * @return string|false 
 */
function exprmgr_strstr($haystack,$needle,$before_needle=false)
{
    return mb_strstr($haystack,$needle,$before_needle,'UTF-8');
}
/**
 * Make an unicode string lowercase 
 * @param string $string
 * @return string
 */
function exprmgr_strtolower($string)
{
    return mb_strtolower ($string,'UTF-8');
}
/**
 * Make an unicode string uppercase 
 * @param string $string
 * @return string
 */
function exprmgr_strtoupper($string)
{
    return mb_strtoupper ($string,'UTF-8');
}
/**
 * Get part of unicode string
 * @param string $string
 * @param int $start
 * @param int $end
 * @return string
 */
function exprmgr_substr($string,$start,$end=null)
{
    return mb_substr($string,$start,$end,'UTF-8');
}
/**
 * Sum of values of answered questions which meet the criteria (arg op value)
 * @param <type> $args
 * @return int
 */
function exprmgr_sumifop($args)
{
    $result=0;
    $op = array_shift($args);
    $value = array_shift($args);
    foreach ($args as $arg)
    {
        switch($op)
        {
            case '==':  case 'eq': if ($arg == $value) { $result += $arg; } break;
            case '>=':  case 'ge': if ($arg >= $value) { $result += $arg; } break;
            case '>':   case 'gt': if ($arg > $value) { $result += $arg; } break;
            case '<=':  case 'le': if ($arg <= $value) { $result += $arg; } break;
            case '<':   case 'lt': if ($arg < $value) { $result += $arg; } break;
            case '!=':  case 'ne': if ($arg != $value) { $result += $arg; } break;
            case 'RX':
                try {
                    if (@preg_match($value, $arg))
                    {
                        $result += $arg;
                    }
                }
                catch (Exception $e) { }
                break;
        }
    }
    return $result;
}

/**
 * Find the closest matching numerical input values in a list an replace it by the
 * corresponding value within another list 
 * 
 * @author Johannes Weberhofer, 2013
 *
 * @param numeric $fValueToReplace
 * @param numeric $iStrict - 1 for exact matches only otherwise interpolation the 
 * 		  closest value should be returned
 * @param string $sTranslateFromList - comma seperated list of numeric values to translate from
 * @param string $sTranslateToList - comma seperated list of numeric values to translate to
 * @return numeric
 */
function exprmgr_convert_value($fValueToReplace, $iStrict, $sTranslateFromList, $sTranslateToList) 
{
	if ( (is_numeric($fValueToReplace)) && ($iStrict!=null) && ($sTranslateFromList!=null) && ($sTranslateToList!=null) ) 
	{
		$aFromValues = explode( ',', $sTranslateFromList);
		$aToValues = explode( ',', $sTranslateToList);
		if ( (count($aFromValues) > 0)  && (count($aFromValues) == count($aToValues)) )
		{
			$fMinimumDiff = null;
			$iNearestIndex = 0;
			for ( $i = 0; $i < count($aFromValues); $i++) {
				if ( !is_numeric($aFromValues[$i])) {
					// break processing when non-numeric variables are about to be processed
					return null;
				}
				$fCurrentDiff = abs($aFromValues[$i] - $fValueToReplace);
				if ($fCurrentDiff === 0) {
					return $aToValues[$i];
				} else if ($i === 0) {
					$fMinimumDiff = $fCurrentDiff;
				} else if ( $fMinimumDiff > $fCurrentDiff ) {
					$fMinimumDiff = $fCurrentDiff;
					$iNearestIndex = $i;
				}
			}					
			if ( $iStrict !== 1 ) {
				return $aToValues[$iNearestIndex];
			}
		}
	}
	return null;
}

/**
 * If $test is true, return $ok, else return $error
 * @param <type> $test
 * @param <type> $ok
 * @param <type> $error
 * @return <type>
 */
function exprmgr_if($test,$ok,$error)
{
    if ($test)
    {
        return $ok;
    }
    else
    {
        return $error;
    }
}

/**
 * Return true if the variable is an integer for LimeSurvey
 * Can not really use is_int due to SQL DECIMAL system
 * @param string $arg
 * @return boolean
 * @link http://php.net/is_int#82857
 */
function exprmgr_int($arg)
{
    if(strpos($arg,"."))
        $arg=preg_replace("/\.$/","",rtrim(strval($arg),"0"));// DECIMAL from SQL return always .00000000, the remove all 0 and one . , see #09550
    return (ctype_digit($arg));// Accept empty value too before PHP 5.1 see http://php.net/ctype-digit#refsect1-function.ctype-digit-changelog
}
/**
 * Join together $args[0-N] with ', '
 * @param <type> $args
 * @return <type>
 */
function exprmgr_list($args)
{
    $result="";
    $j=1;    // keep track of how many non-null values seen
    foreach ($args as $arg)
    {
        if ($arg != '') {
            if ($j > 1) {
                $result .= ', ' . $arg;
            }
            else {
                $result .= $arg;
            }
            ++$j;
        }
    }
    return $result;
}

/**
 * return log($arg[0],$arg[1]=e)
 * @param <type> $args
 * @return float
 */
function exprmgr_log($args)
{
    if (count($args) < 1)
    {
        return NAN;
    }
    $number=$args[0];
    if(!is_numeric($number)){return NAN;}
    $base=(isset($args[1]))?$args[1]:exp(1);
    if(!is_numeric($base)){return NAN;}
    if(floatval($base)<=0){return NAN;}
    return log($number,$base);
}

/**
 * Join together $args[N]
 * @param <type> $args
 * @return <type>
 */
function exprmgr_join($args)
{
    return implode("",$args);
}

/**
 * Join together $args[1-N] with $arg[0]
 * @param <type> $args
 * @return <type>
 */
function exprmgr_implode($args)
{
    if (count($args) <= 1)
    {
        return "";
    }
    $joiner = array_shift($args);
    return implode($joiner,$args);
}

/**
 * Return true if the variable is NULL or blank.
 * @param <type> $arg
 * @return <type>
 */
function exprmgr_empty($arg)
{
    if ($arg === NULL || $arg === "" || $arg === false) {
        return true;
    }
    return false;
}

/**
 * Compute the Sample Standard Deviation of a set of numbers ($args[0-N])
 * @param <type> $args
 * @return <type>
 */
function exprmgr_stddev($args)
{
    $vals = [];
    foreach ($args as $arg)
    {
        if (is_numeric($arg)) {
            $vals[] = $arg;
        }
    }
    $count = count($vals);
    if ($count <= 1) {
        return 0;   // what should default value be?
    }
    $sum = 0;
    foreach ($vals as $val) {
        $sum += $val;
    }
    $mean = $sum / $count;

    $sumsqmeans = 0;
    foreach ($vals as $val)
    {
        $sumsqmeans += ($val - $mean) * ($val - $mean);
    }
    $stddev = sqrt($sumsqmeans / ($count-1));
    return $stddev;
}

/**
 * Javascript equivalent does not cope well with ENT_QUOTES and related PHP constants, so set default to ENT_QUOTES
 * @param <type> $string
 * @return <type>
 */
function expr_mgr_htmlspecialchars($string)
{
    return htmlspecialchars($string,ENT_QUOTES);
}

/**
 * Javascript equivalent does not cope well with ENT_QUOTES and related PHP constants, so set default to ENT_QUOTES
 * @param <type> $string
 * @return <type>
 */
function expr_mgr_htmlspecialchars_decode($string)
{
    return htmlspecialchars_decode($string,ENT_QUOTES);
}

/**
 * Return true of $input matches the regular expression $pattern
 * @param <type> $pattern
 * @param <type> $input
 * @return <type>
 */
function exprmgr_regexMatch($pattern, $input)
{
    try {
        // 'u' is the regexp modifier for unicode so that non-ASCII string will nbe validated properly
        $result = @preg_match($pattern.'u', $input);
    } catch (Exception $e) {
        $result = false;
        // How should errors be logged?
        echo sprintf(gT('Invalid PERL Regular Expression: %s'), htmlspecialchars($pattern));
    }
    return $result;
}

/**
 * Display number with comma as radix separator, if needed
 * @param type $value
 * @return type
 */
function exprmgr_fixnum($value)
{
    if (LimeExpressionManager::usingCommaAsRadix())
    {
        $newval = implode(',',explode('.',$value));
        return $newval;
    }
    return $value;
}
/**
 * Returns true if all non-empty values are unique
 * @param type $args
 * @return boolean
 */
function exprmgr_unique($args)
{
    $uniqs = [];
    foreach ($args as $arg)
    {
        if (trim($arg)=='')
        {
            continue;   // ignore blank answers
        }
        if (isset($uniqs[$arg]))
        {
            return false;
        }
        $uniqs[$arg]=1;
    }
    return true;
}
