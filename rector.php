<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\BooleanNot\SimplifyDeMorganBinaryRector;
use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\CodeQuality\Rector\ClassMethod\ExplicitReturnNullRector;
use Rector\CodeQuality\Rector\Empty_\SimplifyEmptyCheckOnEmptyArrayRector;
use Rector\CodeQuality\Rector\Expression\InlineIfToExplicitIfRector;
use Rector\CodeQuality\Rector\Foreach_\UnusedForeachValueToArrayKeysRector;
use Rector\CodeQuality\Rector\FuncCall\CompactToVariablesRector;
use Rector\CodeQuality\Rector\FunctionLike\SimplifyUselessVariableRector;
use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
use Rector\CodeQuality\Rector\If_\CombineIfRector;
use Rector\CodeQuality\Rector\If_\ExplicitBoolCompareRector;
use Rector\CodeQuality\Rector\If_\ShortenElseIfRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfElseToTernaryRector;
use Rector\CodeQuality\Rector\If_\SimplifyIfReturnBoolRector;
use Rector\CodeQuality\Rector\Ternary\ArrayKeyExistsTernaryThenValueToCoalescingRector;
use Rector\CodeQuality\Rector\Ternary\SimplifyTernaryIdenticalRector;
use Rector\CodingStyle\Rector\ArrowFunction\StaticArrowFunctionRector;
use Rector\CodingStyle\Rector\Closure\StaticClosureRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\FuncCall\CountArrayToEmptyArrayComparisonRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Array_\RemoveDuplicatedArrayKeyRector;
use Rector\DeadCode\Rector\Assign\RemoveUnusedVariableAssignRector;
use Rector\DeadCode\Rector\Cast\RecastingRemovalRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedConstructorParamRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodParameterRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPromotedPropertyRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;
use Rector\DeadCode\Rector\Expression\RemoveDeadStmtRector;
use Rector\DeadCode\Rector\If_\RemoveAlwaysTrueIfConditionRector;
use Rector\DeadCode\Rector\If_\RemoveDeadInstanceOfRector;
use Rector\DeadCode\Rector\If_\RemoveUnusedNonEmptyArrayBeforeForeachRector;
use Rector\DeadCode\Rector\If_\SimplifyIfElseWithSameContentRector;
use Rector\DeadCode\Rector\Node\RemoveNonExistingVarAnnotationRector;
use Rector\DeadCode\Rector\Property\RemoveUnusedPrivatePropertyRector;
use Rector\DeadCode\Rector\PropertyProperty\RemoveNullPropertyInitializationRector;
use Rector\DeadCode\Rector\Return_\RemoveDeadConditionAboveReturnRector;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\DeadCode\Rector\StmtsAwareInterface\RemoveJustPropertyFetchForAssignRector;
use Rector\EarlyReturn\Rector\Foreach_\ChangeNestedForeachIfsToEarlyContinueRector;
use Rector\EarlyReturn\Rector\If_\ChangeIfElseValueAssignToEarlyReturnRector;
use Rector\EarlyReturn\Rector\If_\ChangeNestedIfsToEarlyReturnRector;
use Rector\EarlyReturn\Rector\If_\RemoveAlwaysElseRector;
use Rector\EarlyReturn\Rector\Return_\PreparedValueToEarlyReturnRector;
use Rector\EarlyReturn\Rector\StmtsAwareInterface\ReturnEarlyIfVariableRector;
use Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Naming\Rector\ClassMethod\RenameParamToMatchTypeRector;
use Rector\Naming\Rector\Foreach_\RenameForeachValueVariableToMatchExprVariableRector;
use Rector\Php74\Rector\Closure\ClosureToArrowFunctionRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php81\Rector\Array_\FirstClassCallableRector;
use Rector\Php81\Rector\ClassMethod\NewInInitializerRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\Privatization\Rector\Property\PrivatizeFinalClassPropertyRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Class_\AddTestsVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Class_\PropertyTypeFromStrictSetterGetterRector;
use Rector\TypeDeclaration\Rector\Class_\ReturnTypeFromStrictTernaryRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddMethodCallBasedStrictParamTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamTypeBasedOnPHPUnitDataProviderRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddParamTypeFromPropertyTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddReturnTypeDeclarationBasedOnParentClassMethodRector;
use Rector\TypeDeclaration\Rector\ClassMethod\AddVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\ClassMethod\BoolReturnTypeFromStrictScalarReturnsRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NumericReturnTypeFromStrictScalarReturnsRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByMethodCallTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ParamTypeByParentCallTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnNeverTypeRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnDirectArrayRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromReturnNewRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictBoolReturnExprRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictConstantReturnRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictFluentReturnRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNativeCallRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNewArrayRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictParamRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictScalarReturnExprRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictTypedPropertyRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictArrayParamDimFetchRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StrictStringParamConcatRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Empty_\EmptyOnNullableObjectToInstanceOfRector;
use Rector\TypeDeclaration\Rector\FunctionLike\AddParamTypeSplFixedArrayRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictConstructorRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictSetUpRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        __DIR__.'/vendor',
        __DIR__.'/build',
        __DIR__.'/node_modules',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        strictBooleans: true,
    )
    ->withRules([
        // PHP 8.4 features
        AddOverrideAttributeToOverriddenMethodsRector::class,

        // PHP 8.2 features
        ReadOnlyClassRector::class,

        // PHP 8.1 features
        ReadOnlyPropertyRector::class,
        NewInInitializerRector::class,
        FirstClassCallableRector::class,

        // PHP 8.0 features
        ClassPropertyAssignToConstructorPromotionRector::class,

        // Code quality
        InlineConstructorDefaultToPropertyRector::class,
        SimplifyUselessVariableRector::class,
        SimplifyIfReturnBoolRector::class,
        SimplifyIfElseToTernaryRector::class,
        CombineIfRector::class,
        ShortenElseIfRector::class,
        SimplifyBoolIdenticalTrueRector::class,
        SimplifyTernaryIdenticalRector::class,
        SimplifyDeMorganBinaryRector::class,
        FlipTypeControlToUseExclusiveTypeRector::class,
        ExplicitBoolCompareRector::class,
        InlineIfToExplicitIfRector::class,
        UnusedForeachValueToArrayKeysRector::class,
        CompactToVariablesRector::class,
        ArrayKeyExistsTernaryThenValueToCoalescingRector::class,
        SimplifyEmptyCheckOnEmptyArrayRector::class,
        ExplicitReturnNullRector::class,

        // Coding style
        StaticClosureRector::class,
        StaticArrowFunctionRector::class,
        ClosureToArrowFunctionRector::class,
        CountArrayToEmptyArrayComparisonRector::class,
        PostIncDecToPreIncDecRector::class,
        EncapsedStringsToSprintfRector::class,

        // Dead code
        RemoveUnusedPrivateMethodRector::class,
        RemoveUnusedPrivatePropertyRector::class,
        RemoveUnusedVariableAssignRector::class,
        RemoveEmptyClassMethodRector::class,
        RemoveDeadStmtRector::class,
        RemoveAlwaysTrueIfConditionRector::class,
        RemoveDeadInstanceOfRector::class,
        RemoveUnreachableStatementRector::class,
        RemoveDeadConditionAboveReturnRector::class,
        SimplifyIfElseWithSameContentRector::class,
        RemoveUnusedNonEmptyArrayBeforeForeachRector::class,
        RemoveNonExistingVarAnnotationRector::class,
        RemoveUnusedConstructorParamRector::class,
        RemoveUnusedPrivateMethodParameterRector::class,
        RemoveUnusedPromotedPropertyRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
        RemoveNullPropertyInitializationRector::class,
        RemoveDuplicatedArrayKeyRector::class,
        RecastingRemovalRector::class,
        RemoveConcatAutocastRector::class,
        RemoveJustPropertyFetchForAssignRector::class,

        // Early return
        ChangeNestedIfsToEarlyReturnRector::class,
        ChangeNestedForeachIfsToEarlyContinueRector::class,
        ChangeIfElseValueAssignToEarlyReturnRector::class,
        RemoveAlwaysElseRector::class,
        PreparedValueToEarlyReturnRector::class,
        ReturnEarlyIfVariableRector::class,

        // Naming
        RenameVariableToMatchMethodCallReturnTypeRector::class,
        RenamePropertyToMatchTypeRector::class,
        RenameParamToMatchTypeRector::class,
        RenameForeachValueVariableToMatchExprVariableRector::class,

        // Type declarations
        AddVoidReturnTypeWhereNoReturnRector::class,
        AddClosureVoidReturnTypeWhereNoReturnRector::class,
        AddArrowFunctionReturnTypeRector::class,
        AddTestsVoidReturnTypeWhereNoReturnRector::class,
        ReturnTypeFromStrictBoolReturnExprRector::class,
        ReturnTypeFromStrictConstantReturnRector::class,
        ReturnTypeFromStrictNativeCallRector::class,
        ReturnTypeFromStrictNewArrayRector::class,
        ReturnTypeFromStrictParamRector::class,
        ReturnTypeFromStrictScalarReturnExprRector::class,
        ReturnTypeFromStrictTypedPropertyRector::class,
        ReturnTypeFromStrictFluentReturnRector::class,
        ReturnTypeFromReturnNewRector::class,
        ReturnTypeFromReturnDirectArrayRector::class,
        ReturnNeverTypeRector::class,
        BoolReturnTypeFromStrictScalarReturnsRector::class,
        NumericReturnTypeFromStrictScalarReturnsRector::class,
        ReturnTypeFromStrictTernaryRector::class,
        TypedPropertyFromStrictConstructorRector::class,
        TypedPropertyFromStrictSetUpRector::class,
        PropertyTypeFromStrictSetterGetterRector::class,
        AddMethodCallBasedStrictParamTypeRector::class,
        AddParamTypeFromPropertyTypeRector::class,
        AddReturnTypeDeclarationBasedOnParentClassMethodRector::class,
        ParamTypeByMethodCallTypeRector::class,
        ParamTypeByParentCallTypeRector::class,
        AddParamTypeSplFixedArrayRector::class,
        AddParamTypeBasedOnPHPUnitDataProviderRector::class,
        StrictArrayParamDimFetchRector::class,
        StrictStringParamConcatRector::class,
        EmptyOnNullableObjectToInstanceOfRector::class,
        NullToStrictStringFuncCallArgRector::class,

        // Privatization
        PrivatizeFinalClassMethodRector::class,
        PrivatizeFinalClassPropertyRector::class,

        // Strict
        DisallowedEmptyRuleFixerRector::class,
    ])
    ->withImportNames(
        importNames: true,
        importDocBlockNames: true,
        importShortClasses: false,
        removeUnusedImports: true,
    );
