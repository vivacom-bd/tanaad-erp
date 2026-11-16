// Real-mode documents: POST /documents/{kind}/{id}/render answers the document as data; the screens print it. Never used in demo mode.
import { ApiError, api } from './http';
import { loadSession } from './auth';

export type DocumentKind = 'delivery_note' | 'invoice' | 'statement' | 'payslip';
type Obj = Record<string, unknown>;
export interface ServerDocument { kind: DocumentKind; id: string; content_hash?: string; company?: Obj; site?: Obj; [k: string]: unknown }

export const documentTitle: Record<DocumentKind, string> = { delivery_note: 'Delivery note', invoice: 'Invoice', statement: 'Statement of account', payslip: 'Payslip' };

/** Plain wording for a refused render (403 other site, 409 no note issued yet, 404, offline). */
export function documentErrorMessage(e: { status?: number; code?: string; detail?: string }): string {
  if (e.code === 'network') return 'No connection. Documents are produced by the server; try again when online.';
  if (e.status === 403) return e.detail || 'Your role may not print this document.';
  if (e.status === 404) return e.detail || 'The server does not have this record yet. Wait for it to sync.';
  return e.detail || 'The document could not be produced.';
}

export async function renderDocument(kind: DocumentKind, id: string): Promise<ServerDocument> {
  const s = loadSession(); if (!s) throw new Error('Sign in again to print.');
  try { return (await api<{ document: ServerDocument }>(`/documents/${kind}/${encodeURIComponent(id)}/render`, { method: 'POST', token: s.token })).document; }
  catch (e) { throw e instanceof ApiError ? new Error(documentErrorMessage(e)) : e; }
}
