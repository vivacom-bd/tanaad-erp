<?php

declare(strict_types=1);

namespace Tanaad\Documents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tanaad\Documents\Builders\DeliveryNoteBuilder;
use Tanaad\Documents\Builders\DocumentBuilder;
use Tanaad\Documents\Builders\InvoiceBuilder;
use Tanaad\Documents\Builders\PayslipBuilder;
use Tanaad\Documents\Support\Company;
use Tanaad\Identity\Access;
use Tanaad\Identity\Models\User;
use Tanaad\Shared\Http\ApiController;
use Tanaad\Shared\Ledger\Rejection;

/**
 * POST /documents/{kind}/{id}/render. Returns the document as structured data (JSON). With ?format=pdf and
 * tanaad.documents.pdf on, Browsershot renders it; the package is not installed yet, so that answers 501.
 */
final class DocumentController extends ApiController
{
    public function __construct(private readonly Access $access, private readonly Company $company, private readonly \Illuminate\Database\ConnectionInterface $db) {}

    public function render(Request $request, string $kind, string $id): JsonResponse|Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw Rejection::forbidden('Sign in first.');
        }
        $builder = $this->builder($kind);
        if ($builder === null || ! $builder->available()) {
            throw Rejection::notFound('Document kind', $kind);
        }
        $document = $builder->build($user->loadMissing('siteRoles'), $id);
        $document = ['kind' => $kind, 'id' => $id, ...$document];
        // Integrity checksum of exactly the data returned (SHA-256 of its JSON), so a copy can be checked for
        // changes. It is NOT a signature: anyone can recompute it, so it proves nothing about who issued it.
        $document['content_hash'] = hash('sha256', json_encode($document, JSON_THROW_ON_ERROR));

        if ($request->input('format', $request->query('format')) === 'pdf') {
            return $this->pdf($document);
        }

        return new JsonResponse(['document' => $document]);
    }

    private function builder(string $kind): ?DocumentBuilder
    {
        return match ($kind) {
            'delivery_note' => new DeliveryNoteBuilder($this->db, $this->access, $this->company),
            'payslip' => new PayslipBuilder($this->db, $this->access, $this->company),
            'invoice' => new InvoiceBuilder($this->db, $this->access, $this->company),
            'statement' => new InvoiceBuilder($this->db, $this->access, $this->company, true),
            default => null,
        };
    }

    /** @param array<string, mixed> $document */
    private function pdf(array $document): JsonResponse|Response
    {
        $class = 'Spatie\\Browsershot\\Browsershot';
        if (! (bool) config('tanaad.documents.pdf', false) || ! class_exists($class)) {
            return new JsonResponse(['code' => 'not_implemented', 'reason' => 'PDF output is not available; ask for the data without format=pdf.', 'details' => []], 501);
        }
        $html = '<html><body><pre>'.e((string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).'</pre></body></html>';
        $pdf = $class::html($html)->pdf();

        return new Response(is_string($pdf) ? $pdf : '', 200, ['Content-Type' => 'application/pdf']);
    }
}
