<?php

declare(strict_types=1);

namespace Prism\Harness\Math;

use InvalidArgumentException;
use JsonException;
use Prism\Harness\Contracts\FormulaEngine;
use stdClass;

/** Plain service: no Laravel, model calls, evaluator or I/O of its own. */
final class DoMathService
{
    /** @var array<string, array{document: array<string, mixed>, checked: bool}> */
    private array $documents = [];

    public function __construct(
        private readonly FormulaPolicy $policy,
        private readonly ?FormulaEngine $engine = null,
        private readonly ?string $unavailableDetail = null,
    ) {}

    /**
     * Host boot/save-time API. A missing engine records UNCHECKED and requires
     * a worker restart plus fresh registration; this service never hot-swaps it.
     *
     * @param  array<string, mixed>  $document
     */
    public function registerDocument(string $name, array $document): void
    {
        if (! in_array($name, $this->policy->documents, true)) {
            throw new InvalidArgumentException('Register formula documents under the agent host allowlist.');
        }
        if ($this->engine === null) {
            $this->documents[$name] = ['document' => $document, 'checked' => false];

            return;
        }
        $snapshot = $this->snapshot($document);
        $policy = $this->documentPolicy($snapshot);
        $check = $this->engine->check($this->snapshot($snapshot), $policy);
        if (! $check->ok()) {
            throw new FormulaDocumentRejected($check);
        }
        $this->documents[$name] = ['document' => $snapshot, 'checked' => true];
    }

    public function documentStatus(string $name): ?string
    {
        return isset($this->documents[$name]) ? ($this->documents[$name]['checked'] ? 'CHECKED' : 'UNCHECKED') : null;
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function evaluate(array $request): array
    {
        // Deliberately before request validation, document resolution or parsing.
        if ($this->engine === null) {
            $error = ['code' => 'FORMULA_ENGINE_UNAVAILABLE', 'package' => 'particle-academy/fancy-formula'];
            if ($this->unavailableDetail !== null) {
                $error['detail'] = $this->unavailableDetail;
            }

            return ['ok' => false, 'engine' => null, 'error' => $error];
        }
        $policy = $this->policy;
        try {
            if (array_diff(array_keys($request), ['document', 'inputs', 'limits']) !== []) {
                throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'A formula request cannot set host policy, clock or seed.');
            }
            $selector = $request['document'] ?? null;
            $adHoc = is_array($selector);
            if ($adHoc) {
                if (! $policy->allowAdHoc) {
                    throw new FormulaRequestRefused('DO_MATH_AD_HOC_DISABLED', 'Ad-hoc formulas are disabled for this agent.');
                }
                $document = $this->snapshot($selector);
            } else {
                if (! is_string($selector) || ! in_array($selector, $policy->documents, true)) {
                    throw new FormulaRequestRefused('DO_MATH_DOCUMENT_NOT_ALLOWED', 'The formula document is not allowed for this agent.');
                }
                $stored = $this->documents[$selector] ?? null;
                if ($stored === null || ! $stored['checked']) {
                    throw new FormulaRequestRefused('DO_MATH_DOCUMENT_UNCHECKED', 'The host must register and check this document before use.');
                }
                $document = $this->snapshot($stored['document']);
            }
            $policy = $this->documentPolicy($document);
            if (! is_array($request['limits'] ?? [])) {
                throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'Request limits must be an object.');
            }
            $policy = $policy->narrowed($request['limits'] ?? []);
            $inputs = $request['inputs'] ?? [];
            if (! is_array($inputs) || ($inputs !== [] && array_is_list($inputs))) {
                throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'Inputs must be a map of values.');
            }
            foreach ($inputs as $name => $_) {
                if (! is_array($document['inputs'] ?? null) || ! array_key_exists($name, $document['inputs'])) {
                    throw new FormulaRequestRefused('DO_MATH_INPUT_NOT_ALLOWED', 'Only declared formula inputs may be supplied.');
                }
            }
            if ($adHoc || ($request['limits'] ?? []) !== []) {
                $check = $this->engine->check($this->snapshot($document), $policy);
                if (! $check->ok()) {
                    return $this->result(false, $check->report, $policy);
                }
            }
            $report = $this->engine->evaluate($this->snapshot($document), $this->snapshot($inputs), $policy)->report;

            return $this->result($report['ok'] === true, $report, $policy);
        } catch (FormulaRequestRefused $failure) {
            return $this->result(false, ['code' => $failure->errorCode, 'message' => $failure->getMessage()], $policy);
        }
    }

    /** @param array<string, mixed> $document */
    private function documentPolicy(array $document): FormulaPolicy
    {
        if (array_diff(array_keys($document), ['$schema', 'version', 'formulas', 'inputs', 'names', 'outputs', 'trace', 'limits']) !== []) {
            throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'A formula document cannot supply host policy, providers, clock or seed.');
        }
        if (! is_array($document['limits'] ?? [])) {
            throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'Document limits must be an object.');
        }
        if (array_diff(array_keys($document['limits'] ?? []), array_keys(FormulaPolicy::ENGINE_LIMITS)) !== []) {
            throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'Document limits must use engine request keys.');
        }
        $policy = $this->policy->narrowed($document['limits'] ?? []);
        $levels = ['none' => 0, 'lookups' => 1, 'full' => 2];
        $trace = $document['trace'] ?? $policy->trace;
        if (! is_string($trace) || ! isset($levels[$trace]) || $levels[$trace] > $levels[$policy->trace]) {
            throw new FormulaRequestRefused('DO_MATH_POLICY_WIDENING', 'The document cannot widen the host trace policy.');
        }

        return $policy->withTrace($trace);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function result(bool $ok, array $report, FormulaPolicy $policy): array
    {
        $version = $this->engine?->version();
        if (! is_string($version) || strlen($version) > 128 || preg_match('/\A[a-zA-Z0-9 ._-]+\z/D', $version) !== 1) {
            throw new InvalidArgumentException('The engine must supply a bounded version label.');
        }
        try {
            $report = $this->snapshot($report);
            if ($ok && (! is_string($report['fingerprint'] ?? null) || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $report['fingerprint']) !== 1)) {
                throw new FormulaRequestRefused('DO_MATH_ENGINE_REPORT_INVALID', 'The engine success requires a reproducible fingerprint.');
            }
            $wire = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            $traceBytes = strlen(json_encode($report['traces'] ?? [], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        } catch (FormulaRequestRefused|JsonException) {
            return ['ok' => false, 'engine' => $version, 'fingerprint' => null,
                'error' => ['code' => 'DO_MATH_ENGINE_REPORT_INVALID']];
        }
        if (strlen($wire) > $policy->maxOutputBytes || $traceBytes > $policy->maxTraceBytes) {
            return $this->outputLimit($report, $version, $policy);
        }
        $result = $ok ? $report : ['ok' => false, 'error' => $report];
        $result['engine'] = $version;
        $result['fingerprint'] = is_string($report['fingerprint'] ?? null) ? $report['fingerprint'] : null;
        $paths = [];
        if (! $this->markText($result, '', $paths)) {
            return [...$this->outputLimit($report, $version, $policy), 'text_safety' => ['complete' => false]];
        }
        $result['text_safety'] = ['format' => 'json-data', 'complete' => true, 'formula_like_paths' => $paths];
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) > $policy->maxOutputBytes) {
            return $this->outputLimit($report, $version, $policy);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function outputLimit(array $report, string $version, FormulaPolicy $policy): array
    {
        $truncated = $report['truncatedViolations'] ?? [];
        $sum = 0;
        if (is_array($truncated) || $truncated instanceof stdClass) {
            foreach ($truncated as $count) {
                if (is_int($count) && $count > 0) {
                    $sum = min(PHP_INT_MAX - $sum, $count) + $sum;
                }
            }
        }

        return ['ok' => false, 'engine' => $version, 'fingerprint' => null, 'error' => [
            'code' => 'FORMULA_OUTPUT_LIMIT',
            'engine_code' => is_string($report['code'] ?? null) && strlen($report['code']) <= 96 ? $report['code'] : null,
            'counts' => [
                'violations' => is_array($report['violations'] ?? null) ? count($report['violations']) : 0,
                'truncatedViolations' => $sum,
                'truncatedByRequestCap' => is_int($report['truncatedByRequestCap'] ?? null) ? $report['truncatedByRequestCap'] : 0,
            ],
            'limit_bytes' => $policy->maxOutputBytes,
        ]];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function snapshot(array $value): array
    {
        $copy = $this->copyValue($value, 0);
        assert(is_array($copy));

        return $copy;
    }

    private function copyValue(mixed $value, int $depth): mixed
    {
        if ($depth > 64) {
            throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'Formula data must be finite JSON values.');
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = $this->copyValue($item, $depth + 1);
            }

            return $copy;
        }
        if ($value instanceof stdClass) {
            $copy = new stdClass;
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = $this->copyValue($item, $depth + 1);
            }

            return $copy;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }

        throw new FormulaRequestRefused('DO_MATH_REQUEST_INVALID', 'Formula data must contain JSON values only.');
    }

    /** @param list<string> $paths */
    private function markText(mixed $value, string $path, array &$paths): bool
    {
        if (is_string($value) && preg_match('/\A[=+\-@\t\r]/', $value) === 1) {
            if (count($paths) >= 32) {
                return false;
            }
            $paths[] = $path;
        } elseif (is_array($value) || $value instanceof stdClass) {
            foreach ($value as $key => $item) {
                if (! $this->markText($item, $path.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key), $paths)) {
                    return false;
                }
            }
        }

        return true;
    }
}
