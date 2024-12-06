<?php

declare(strict_types=1);

namespace Rector\NodeManipulator;

use Doctrine\ORM\Mapping\Table;
use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\NodeAnalyzer\PropertyFetchAnalyzer;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\NodeTypeResolver\NodeTypeResolver;
use Rector\Php80\NodeAnalyzer\PhpAttributeAnalyzer;
use Rector\Php80\NodeAnalyzer\PromotedPropertyResolver;
use Rector\PhpParser\AstResolver;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\PhpParser\NodeFinder\PropertyFetchFinder;
use Rector\TypeDeclaration\AlreadyAssignDetector\ConstructorAssignDetector;
use Rector\ValueObject\MethodName;

/**
 * For inspiration to improve this service,
 * @see examples of variable modifications in https://wiki.php.net/rfc/readonly_properties_v2#proposal
 */
final readonly class PropertyManipulator
{
    /**
     * @var string[]|class-string<Table>[]
     */
    private const ALLOWED_NOT_READONLY_CLASS_ANNOTATIONS = [
        'Doctrine\ORM\Mapping\Entity',
        'Doctrine\ORM\Mapping\Table',
        'Doctrine\ORM\Mapping\MappedSuperclass',
        'Doctrine\ORM\Mapping\Embeddable',
    ];

    /**
     * @var string[]|class-string<Table>[]
     */
    private const ALLOWED_NOT_READONLY_CLASS_ATTRIBUTES = [
        'ApiPlatform\Metadata\ApiResource',
        ...self::ALLOWED_NOT_READONLY_CLASS_ANNOTATIONS,
    ];

    public function __construct(
        private AssignManipulator $assignManipulator,
        private BetterNodeFinder $betterNodeFinder,
        private PhpDocInfoFactory $phpDocInfoFactory,
        private PropertyFetchFinder $propertyFetchFinder,
        private NodeNameResolver $nodeNameResolver,
        private PhpAttributeAnalyzer $phpAttributeAnalyzer,
        private NodeTypeResolver $nodeTypeResolver,
        private PromotedPropertyResolver $promotedPropertyResolver,
        private ConstructorAssignDetector $constructorAssignDetector,
        private AstResolver $astResolver,
        private PropertyFetchAnalyzer $propertyFetchAnalyzer
    ) {
    }

    public function isPropertyChangeableExceptConstructor(
        Class_ $class,
        Property | Param $propertyOrParam,
        Scope $scope
    ): bool {
        $phpDocInfo = $this->phpDocInfoFactory->createFromNodeOrEmpty($class);

        if ($this->hasAllowedNotReadonlyAnnotationOrAttribute($phpDocInfo, $class)) {
            return true;
        }

        $propertyFetches = $this->propertyFetchFinder->findPrivatePropertyFetches($class, $propertyOrParam, $scope);
        $classMethod = $class->getMethod(MethodName::CONSTRUCT);

        foreach ($propertyFetches as $propertyFetch) {
            if ($this->isChangeableContext($propertyFetch)) {
                return true;
            }

            // skip for constructor? it is allowed to set value in constructor method
            $propertyName = (string) $this->nodeNameResolver->getName($propertyFetch);

            if ($this->isPropertyAssignedOnlyInConstructor($class, $propertyName, $propertyFetch, $classMethod)) {
                continue;
            }

            if ($this->assignManipulator->isLeftPartOfAssign($propertyFetch)) {
                return true;
            }

            if ($propertyFetch->getAttribute(AttributeKey::IS_UNSET_VAR) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @api Used in rector-symfony
     */
    public function resolveExistingClassPropertyNameByType(Class_ $class, ObjectType $objectType): ?string
    {
        foreach ($class->getProperties() as $property) {
            $propertyType = $this->nodeTypeResolver->getType($property);
            if (! $propertyType->equals($objectType)) {
                continue;
            }

            return $this->nodeNameResolver->getName($property);
        }

        $promotedPropertyParams = $this->promotedPropertyResolver->resolveFromClass($class);
        foreach ($promotedPropertyParams as $promotedPropertyParam) {
            $paramType = $this->nodeTypeResolver->getType($promotedPropertyParam);
            if (! $paramType->equals($objectType)) {
                continue;
            }

            return $this->nodeNameResolver->getName($promotedPropertyParam);
        }

        return null;
    }

    public function isUsedByTrait(ClassReflection $classReflection, string $propertyName): bool
    {
        foreach ($classReflection->getTraits() as $traitUse) {
            $trait = $this->astResolver->resolveClassFromClassReflection($traitUse);
            if (! $trait instanceof Trait_) {
                continue;
            }

            if ($this->propertyFetchAnalyzer->containsLocalPropertyFetchName($trait, $propertyName)) {
                return true;
            }
        }

        return false;
    }

    private function isPropertyAssignedOnlyInConstructor(
        Class_ $class,
        string $propertyName,
        StaticPropertyFetch|PropertyFetch $propertyFetch,
        ?ClassMethod $classMethod
    ): bool {
        if (! $classMethod instanceof ClassMethod) {
            return false;
        }

        $node = $this->betterNodeFinder->findFirst(
            (array) $classMethod->stmts,
            static fn (Node $subNode): bool => ($subNode instanceof PropertyFetch || $subNode instanceof StaticPropertyFetch) && $subNode === $propertyFetch
        );

        // there is property unset in Test class, so only check on __construct
        if (! $node instanceof Node) {
            return false;
        }

        return $this->constructorAssignDetector->isPropertyAssigned($class, $propertyName);
    }

    private function isChangeableContext(PropertyFetch | StaticPropertyFetch $propertyFetch): bool
    {
        if ($propertyFetch->getAttribute(AttributeKey::IS_UNSET_VAR, false)) {
            return true;
        }

        if ($propertyFetch->getAttribute(AttributeKey::INSIDE_ARRAY_DIM_FETCH, false)) {
            return true;
        }

        if ($propertyFetch->getAttribute(AttributeKey::IS_USED_AS_ARG_BY_REF_VALUE, false) === true) {
            return true;
        }

        return $propertyFetch->getAttribute(AttributeKey::IS_INCREMENT_OR_DECREMENT, false) === true;
    }

    private function hasAllowedNotReadonlyAnnotationOrAttribute(PhpDocInfo $phpDocInfo, Class_ $class): bool
    {
        if ($phpDocInfo->hasByAnnotationClasses(self::ALLOWED_NOT_READONLY_CLASS_ANNOTATIONS)) {
            return true;
        }

        return $this->phpAttributeAnalyzer->hasPhpAttributes($class, self::ALLOWED_NOT_READONLY_CLASS_ATTRIBUTES);
    }
}
