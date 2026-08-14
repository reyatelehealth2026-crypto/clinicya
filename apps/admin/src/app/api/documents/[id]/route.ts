import { NextResponse } from 'next/server';
import { docStatusLabel, docTypeLabel, formatThaiDate } from '@reya/core';
import { documentsFetch, phpIntCast } from '../_lib/documents';
import { resolveDocumentsApiContext } from '../_lib/session';

/**
 * GET /api/documents/[id] — port of api/documents.php's `case 'get':`
 * (lines 282-294).
 *
 * ROUTING SHAPE DEVIATES FROM PHP DELIBERATELY: PHP reads `$_GET['id']`
 * (`?action=get&id=N`); this port takes `id` from the dynamic route
 * segment instead — same precedent as
 * api/inbox/actions/notes/[noteId]/route.ts's own module doc (a
 * GET/DELETE-by-id sub-resource route is the more idiomatic Next.js
 * shape), importing `session.ts` from `../_lib/session` the same way that
 * file imports its sibling's.
 */
export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }): Promise<NextResponse> {
  const auth = await resolveDocumentsApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  const { id: idParam } = await params;
  const id = phpIntCast(idParam);
  if (id <= 0) {
    return NextResponse.json({ success: false, error: 'bad_id' });
  }

  try {
    const doc = await documentsFetch(db, lineAccountId, id);
    if (!doc) {
      return NextResponse.json({ success: false, error: 'not_found' }, { status: 404 });
    }

    return NextResponse.json({
      success: true,
      data: {
        ...doc,
        doc_type_label: docTypeLabel(doc.doc_type),
        status_label: docStatusLabel(doc.status),
        issue_date_thai: formatThaiDate(doc.issue_date),
      },
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to get document: ${message}` }, { status: 400 });
  }
}
