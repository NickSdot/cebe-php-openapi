<?php

/**
 * @copyright Copyright (c) 2018 Carsten Brandt <mail@cebe.cc> and contributors
 * @license https://github.com/cebe/php-openapi/blob/master/LICENSE
 */

namespace cebe\openapi\spec;

use cebe\openapi\DocumentContextInterface;
use cebe\openapi\exceptions\IOException;
use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\exceptions\UnresolvableReferenceException;
use cebe\openapi\json\InvalidJsonPointerSyntaxException;
use cebe\openapi\json\JsonPointer;
use cebe\openapi\json\JsonReference;
use cebe\openapi\json\NonexistentJsonPointerReferenceException;
use cebe\openapi\ReferenceContext;
use cebe\openapi\SpecObjectInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Reference Object
 *
 * @link https://github.com/OAI/OpenAPI-Specification/blob/3.0.2/versions/3.0.2.md#referenceObject
 * @link https://tools.ietf.org/html/draft-pbryan-zyp-json-ref-03
 * @link https://tools.ietf.org/html/rfc6901
 *
 */
class Reference implements SpecObjectInterface, DocumentContextInterface
{
    /**
     * @var string
     */
    private $_to;
    /**
     * @var string
     */
    private $_ref;
    /**
     * @var JsonReference|null
     */
    private $_jsonReference;
    /**
     * @var ReferenceContext
     */
    private $_context;
    /**
     * @var SpecObjectInterface|null
     */
    private $_baseDocument;
    /**
     * @var JsonPointer|null
     */
    private $_jsonPointer;
    /**
     * @var array
     */
    private $_errors = [];

    private $_recursingInsideFile = false;

    /**
     * Create an object from spec data.
     * @param array $data spec data read from YAML or JSON
     * @param string $to class name of the type referenced by this Reference
     * @throws TypeErrorException in case invalid data is supplied.
     */
    public function __construct(array $data, string $to = null)
    {
        // From 'Reference Object' spec:
        // REQUIRED. The reference identifier. This MUST be in the form of a URI.
        // https://spec.openapis.org/oas/v3.1.0#fixed-fields-18
        // Note: Symfony YAML parser bug: https://github.com/symfony/symfony/pull/51444
        if (!isset($data['$ref']) || $data['$ref'] === '') {
            throw new TypeErrorException(
                "Reference Object requires field '\$ref' with a non-empty value. Data given: '" . print_r($data, true) . "'."
            );
        }

        if ($to !== null && !is_subclass_of($to, SpecObjectInterface::class, true)) {
            throw new TypeErrorException(
                "Unable to instantiate Reference Object, Referenced Class type must implement SpecObjectInterface."
            );
        }
        if (!is_string($data['$ref'])) {
            throw new TypeErrorException(
                'Unable to instantiate Reference Object, value of $ref must be a string.'
            );
        }
        $this->_to = $to;
        $this->_ref = $data['$ref'];
        try {
            $this->_jsonReference = JsonReference::createFromReference($this->_ref);
        } catch (InvalidJsonPointerSyntaxException $e) {
            $this->_errors[] = 'Reference: value of $ref is not a valid JSON pointer: ' . $e->getMessage();
        }
        if (count($data) !== 1) {
            $this->_errors[] = 'Reference: additional properties are given. Only $ref should be set in a Reference Object.';
        }
    }

    /**
     * @return mixed returns the serializable data of this object for converting it
     * to JSON or YAML.
     */
    public function getSerializableData()
    {
        return (object) ['$ref' => $this->_ref];
    }

    /**
     * Validate object data according to OpenAPI spec.
     * @return bool whether the loaded data is valid according to OpenAPI spec
     * @see getErrors()
     */
    public function validate(): bool
    {
        return empty($this->_errors);
    }

    /**
     * @return string[] list of validation errors according to OpenAPI spec.
     * @see validate()
     */
    public function getErrors(): array
    {
        if (($pos = $this->getDocumentPosition()) !== null) {
            return array_map(function ($e) use ($pos) {
                return "[{$pos}] $e";
            }, $this->_errors);
        } else {
            return $this->_errors;
        }
    }

    /**
     * @return string the reference string.
     */
    public function getReference()
    {
        return $this->_ref;
    }

    /**
     * @return JsonReference the JSON Reference.
     */
    public function getJsonReference(): JsonReference
    {
        return $this->_jsonReference;
    }

    /**
     * @param ReferenceContext $context
     */
    public function setContext(ReferenceContext $context)
    {
        $this->_context = $context;
    }

    /**
     * @return ReferenceContext
     */
    public function getContext() : ?ReferenceContext
    {
        return $this->_context;
    }

    /**
     * Resolve this reference.
     * @param ReferenceContext $context the reference context to use for resolution.
     * If not specified, `getContext()` will be called to determine the context, if
     * that does not return a context, the UnresolvableReferenceException will be thrown.
     * @return SpecObjectInterface|array|null the resolved spec type.
     * You might want to call resolveReferences() on the resolved object to recursively resolve recursive references.
     * This is not done automatically to avoid recursion to run into the same function again.
     * If you call resolveReferences() make sure to replace the Reference with the resolved object first.
     * @throws UnresolvableReferenceException in case of errors.
     */
    public function resolve(ReferenceContext $context = null)
    {
        $context ??= $this->getContext();

        if ($context === null) {
            throw new UnresolvableReferenceException('No context given for resolving reference.');
        }

        $jsonReference = $this->_jsonReference;

        if ($jsonReference === null) {
            if ($context->throwException) {
                throw new UnresolvableReferenceException(implode("\n", $this->getErrors()));
            }
            return $this;
        }

        // If the reference is an inline reference, but the current mode
        // only resolves external references, we can return early.

        if ($jsonReference->getDocumentUri() === '' && $context->mode === ReferenceContext::RESOLVE_MODE_INLINE) {
            return $this;
        }

        $baseSpec = $context->getBaseSpec();

        // Cache type is for all following cases the same.
        // The cache pointer might be overridden depending
        // on the specific  case.

        $cacheType = $this->_to;
        $cachePointer = $this->_ref;

        try {

            /**
             * References in the same document
             */

            if ($jsonReference->getDocumentUri() === '' && $baseSpec !== null) {

                if($context->getCache()->has($cachePointer, $cacheType)) {
                    return $context->getCache()->get($cachePointer, $cacheType);
                }

                /** @var SpecObjectInterface $referencedObject */
                $referencedObject = $jsonReference->getJsonPointer()->evaluate($baseSpec); // TODO type error if resolved object does not match $this->_to ?

                // transitive reference
                if ($referencedObject instanceof Reference) {
                    $referencedObject = $this->resolveTransitiveReference($referencedObject, $context);
                }

                if ($referencedObject instanceof SpecObjectInterface) {
                    $referencedObject->setReferenceContext($context);
                }

                $context->getCache()->set($cachePointer, $cacheType, $referencedObject);

                return $referencedObject;

            }

            /**
             * References in external documents
             */

            // multiple files can have the same reference,
            // so we make the cache pointer more specific.
            $cachePointer = $context->resolveRelativeUri(
                $jsonReference->getReference()
            );

            if($context->getCache()->has($cachePointer, $cacheType)) {
                return $context->getCache()->get($cachePointer, $cacheType);
            }

            $file = $context->resolveRelativeUri($jsonReference->getDocumentUri());

            try {
                $referencedDocument = $context->fetchReferencedFile($file);
            } catch (\Throwable $e) {
                $exception = new UnresolvableReferenceException(
                    "Failed to resolve Reference '$this->_ref' to $this->_to Object: " . $e->getMessage(),
                    $e->getCode(),
                    $e
                );
                $exception->context = $this->getDocumentPosition();
                throw $exception;
            }

            $referencedDocument = $this->adjustRelativeReferences($referencedDocument, $file, null, $context);
            $referencedObject = $context->resolveReferenceData($file, $jsonReference->getJsonPointer(), $referencedDocument, $this->_to);

            if ($referencedObject instanceof DocumentContextInterface) {
                if ($referencedObject->getDocumentPosition() === null && $this->getDocumentPosition() !== null) {
                    $referencedObject->setDocumentContext($context->getBaseSpec(), $this->getDocumentPosition());
                }
            }

            // transitive reference
            if ($referencedObject instanceof Reference) {
                if ($context->mode === ReferenceContext::RESOLVE_MODE_INLINE && strncmp($referencedObject->getReference(), '#', 1) === 0) {
                    $referencedObject->setContext($context);
                } else {
                    $referencedObject = $this->resolveTransitiveReference($referencedObject, $context);
                }
            } else {
                if ($referencedObject instanceof SpecObjectInterface) {
                    $referencedObject->setReferenceContext($context);
                }
            }

            $context->getCache()->set($cachePointer,$cacheType, $referencedObject);

            return $referencedObject;

        } catch (NonexistentJsonPointerReferenceException $e) {
            $message = "Failed to resolve Reference '$this->_ref' to $this->_to Object: " . $e->getMessage();
            if ($context->throwException) {
                $exception = new UnresolvableReferenceException($message, 0, $e);
                $exception->context = $this->getDocumentPosition();
                throw $exception;
            }
            $this->_errors[] = $message;
            $this->_jsonReference = null;
            return $this;
        } catch (UnresolvableReferenceException $e) {
            $e->context = $this->getDocumentPosition();
            if ($context->throwException) {
                throw $e;
            }
            $this->_errors[] = $e->getMessage();
            $this->_jsonReference = null;
            return $this;
        }
    }

    private function resolveTransitiveReference(Reference $referencedObject, ReferenceContext $context)
    {
        if ($referencedObject->_to === null) {
            $referencedObject->_to = $this->_to;
        }
        $referencedObject->setContext($context);

        if ($referencedObject === $this) { // catch recursion
            throw new UnresolvableReferenceException('Cyclic reference detected on a Reference Object.');
        }

        $transitiveRefResult = $referencedObject->resolve();

        if ($transitiveRefResult === $this) { // catch recursion
            throw new UnresolvableReferenceException('Cyclic reference detected on a Reference Object.');
        }
        return $transitiveRefResult;
    }

    /**
     * Adjust relative references inside the file to match the context of the base file
     *
     * @noinspection PhpConditionAlreadyCheckedInspection*/
    private function adjustRelativeReferences($referencedDocument, $basePath, $baseDocument = null, ?ReferenceContext $oContext = null)
    {

        $context = new ReferenceContext(null, $basePath);

        if ($baseDocument === null) {
            $baseDocument = $referencedDocument;
        }

        foreach ($referencedDocument as $key => $value) {

            if (is_array($value) === true) {
                $referencedDocument[$key] = $this->adjustRelativeReferences($value, $basePath, $baseDocument, $oContext);
                continue;
            }

            // only values of type string can be references
            // and only keys indicating a reference should be parsed
            if (! is_string($value) || ! in_array($key, ['$ref', 'externalValue'], true)) {
                continue;
            }

            // $this->_to does not apply here
            $fullPath = $basePath . $value;
            $cachePointer = $fullPath;
            $cacheType = 'relativeReference';

            if ($context->getCache()->has($cachePointer, $cacheType)) {
                return $context->getCache()->get($cachePointer, $cacheType);
            }

            // directly inline references in the same document,
            // these are not going to be valid in the new context anymore
            if ($key === '$ref' && str_starts_with($value, '#')) {

                $inlineDocument = (new JsonPointer(substr($value, 1)))->evaluate($baseDocument);

                // keep reference when it is a recursive reference
                if ($this->_recursingInsideFile) {
                    return ['$ref' => $fullPath];
                }

                $this->_recursingInsideFile = true;
                $return = $this->adjustRelativeReferences($inlineDocument, $basePath, $baseDocument, $oContext);
                $this->_recursingInsideFile = false;

                $context->getCache()->set($cachePointer, $cacheType, $return);

                return $return;
            }

            $oContextUri = $oContext->getUri();
            $resolvedUri = $context->resolveRelativeUri($value);

            // adjust reference URLs
            if ($key === '$ref') {

                if (str_starts_with($resolvedUri, $oContextUri)) {
                    $fragment = str_replace($oContextUri, '', $resolvedUri);
                    $referencedDocument[$key] = $fragment ?: '#';
                } else {
                    $referencedDocument[$key] = $this->makeRelativePath($oContextUri, $resolvedUri);
                }
            }

            // adjust externalValue fields  https://spec.openapis.org/oas/v3.0.3#example-object
            if ($key === 'externalValue') {
                $referencedDocument[$key] = $this->makeRelativePath($oContextUri, $resolvedUri);
            }

            $oContext->getCache()->set($cachePointer, $cacheType, $referencedDocument);

        }


        return $referencedDocument;
    }

    /**
     * If $path can be expressed relative to $base, make it a relative path, otherwise $path is returned.
     * @param string $base
     * @param string $path
     */
    private function makeRelativePath($base, $path)
    {
        if (strpos($path, dirname($base)) === 0) {
            return './' . substr($path, strlen(dirname($base) . '/'));
        }

        return $path;
    }

    /**
     * Resolves all Reference Objects in this object and replaces them with their resolution.
     * @throws UnresolvableReferenceException
     */
    public function resolveReferences(ReferenceContext $context = null)
    {
        throw new UnresolvableReferenceException('Cyclic reference detected, resolveReferences() called on a Reference Object.');
    }

    /**
     * Set context for all Reference Objects in this object.
     * @throws UnresolvableReferenceException
     */
    public function setReferenceContext(ReferenceContext $context)
    {
        throw new UnresolvableReferenceException('Cyclic reference detected, setReferenceContext() called on a Reference Object.');
    }

    /**
     * Provide context information to the object.
     *
     * Context information contains a reference to the base object where it is contained in
     * as well as a JSON pointer to its position.
     * @param SpecObjectInterface $baseDocument
     * @param JsonPointer $jsonPointer
     */
    public function setDocumentContext(SpecObjectInterface $baseDocument, JsonPointer $jsonPointer)
    {
        $this->_baseDocument = $baseDocument;
        $this->_jsonPointer = $jsonPointer;
    }

    /**
     * @return SpecObjectInterface|null returns the base document where this object is located in.
     * Returns `null` if no context information was provided by [[setDocumentContext]].
     */
    public function getBaseDocument(): ?SpecObjectInterface
    {
        return $this->_baseDocument;
    }

    /**
     * @return JsonPointer|null returns a JSON pointer describing the position of this object in the base document.
     * Returns `null` if no context information was provided by [[setDocumentContext]].
     */
    public function getDocumentPosition(): ?JsonPointer
    {
        return $this->_jsonPointer;
    }
}
