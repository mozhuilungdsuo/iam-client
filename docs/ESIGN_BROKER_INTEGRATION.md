# IAM eSign Broker Integration Guide

This guide describes only the work a consuming server-side application must implement to integrate with the Nagaland IAM eSign broker. It is framework- and language-neutral: use its HTTPS API from Django, Express, Spring, .NET, Laravel, or another backend.

> The Laravel package offers `EsignBrokerClient` as a convenience wrapper. Applications that do not use Laravel can call the endpoints in this document directly.

## What your application must implement

Your application is responsible for:

- retaining its eSign API key in a server-side secret store;
- uploading the document to IAM and redirecting the browser to the returned handoff URL;
- storing a transaction-to-browser-session association and a random state value;
- validating the callback, checking the final status, and serving the downloaded signed file.

## Prerequisites

Before integration, provide the public `completion_url` for your application to the Department of IT&C. The Department of IT&C will provide the eSign API key.

Store the API key only in your server-side secret manager. The `completion_url` sent when creating a request must exactly match the URL you provided.

## Integration flow

```text
Application backend  -- create request -->  IAM broker
Application browser  <-- handoff URL ------  IAM broker
Application browser  -- redirect -------->  IAM handoff URL
Application browser  <-- completion URL --  IAM broker
Application backend  -- status/download --> IAM broker
```

1. Load the OAuth client's eSign API key from a server-side secret store.
2. Generate a high-entropy `state` value and retain it alongside the browser session and the application record being signed.
3. Call the create endpoint with a unique, application-owned `client_reference` and the uploaded document.
4. Store the returned `transaction_id`, the random state, and the original filename in the browser session before redirecting the browser to `handoff_url`.
5. Redirect the browser to IAM's returned handoff URL. It is single-use and expires after 10 minutes.
6. IAM redirects the browser to `completion_url` with `esign_transaction_id` and `state` when the signing journey finishes.
7. Verify that the transaction belongs to the current browser session and compare state values using a constant-time comparison. Fetch the transaction status from IAM; do not accept a status supplied by the query string.
8. When the status is `completed`, call the download endpoint and stream the returned bytes to the user as a file.

## IAM base URL

Set `IAM_BASE_URL` to the IAM installation URL without a trailing slash.

Example:

```text
https://iam.example.gov.in
```

All endpoint paths in this guide are relative to this base URL.

## Authentication

All API requests need the eSign API key and should request JSON responses:

```http
X-IAM-eSign-Key: <eSign-api-key>
Accept: application/json
```

| Operation | Method and path | Required scope |
| --- | --- | --- |
| Create a request | `POST /api/esign/requests` | `esign.request` |
| Read a request | `GET /api/esign/requests/{transaction_id}` | `esign.read` |
| Download a completed request | `GET /api/esign/requests/{transaction_id}/download` | `esign.read` |

`transaction_id` is IAM's public transaction identifier returned by the create endpoint. Treat it as opaque.

## 1. Create a signing request

Send the document in a multipart request. The `document` part must be a PDF, JPG, JPEG, or PNG and must not exceed 20 MB.

```bash
curl --request POST "$IAM_BASE_URL/api/esign/requests" \
  --header "X-IAM-eSign-Key: $IAM_ESIGN_API_KEY" \
  --header "Accept: application/json" \
  --form "document=@/secure/uploads/approval.pdf;type=application/pdf" \
  --form "client_reference=approval-12345" \
  --form "completion_url=https://client.example.gov.in/esign/complete" \
  --form "state=$ESIGN_STATE" \
  --form "mode=direct" \
  --form "signature_field_name=Approval"
```

| Field | Required | Rules and meaning |
| --- | --- | --- |
| `document` | Yes | PDF, JPG, JPEG, or PNG; maximum 20 MB. |
| `client_reference` | Yes | Application-owned ASCII alpha-dash value, maximum 160 characters. Use a durable unique record identifier. |
| `completion_url` | Yes | Exact eSign Completion URL configured for the OAuth client in IAM Admin; maximum 2,000 characters. |
| `state` | No, strongly recommended | Opaque application value returned unchanged to the completion URL; maximum 255 characters. |
| `mode` | No | `direct` (default) or `visible`. Use `direct` for every request in a document that requires multiple signatures. |
| `signature_field_name` | No | Optional field name for `direct` mode; maximum 120 characters. |
| `signer_name` | No | Optional signer name for `visible` mode; maximum 120 characters. |

### Multiple signatures and signing modes

For a document that will receive more than one signature, use `mode=direct` for **every** signing request. Direct mode appends an invisible signature field without rebuilding the PDF, preserving the document bytes and all signatures already applied. The signed PDF can then be submitted for the next signer.

Visible mode renders a new PDF to add visible signer information. That rebuild does not preserve earlier PDF signatures, so do not use visible mode in a multi-signature sequence. Use it only for a single visible signature on a document that does not need to retain a prior signature.

A newly created request returns HTTP `201 Created` and a payload similar to:

```json
{
  "transaction_id": "5c8e9b5e-33ee-4d79-9aa4-84d0bb08dd0e",
  "client_reference": "approval-12345",
  "status": "awaiting_signer",
  "handoff_url": "https://iam.example.gov.in/esign/handoff/<single-use-token>"
}
```

Redirect the browser to the returned `handoff_url` immediately. Do not fetch the URL from your backend: visiting it begins the browser-facing signing flow.

### Idempotency

`client_reference` is idempotent per OAuth client. If the same client submits it again, IAM returns the existing transaction instead of creating another one. An existing response does not contain a new `handoff_url`, so persist the original transaction association before the first redirect. Generate a new reference only when a new signing attempt is truly intended.

## 2. Receive and verify the completion redirect

After IAM processes the C-DAC response, it redirects the browser to the supplied completion URL:

```text
https://client.example.gov.in/esign/complete?
  esign_transaction_id=5c8e9b5e-33ee-4d79-9aa4-84d0bb08dd0e&
  state=<the-original-state>
```

At the completion endpoint:

1. Read `esign_transaction_id` and `state` from the query string.
2. Look up the transaction ID in the current browser's server-side session or equivalent protected store.
3. Reject the request if it is absent or the received state differs from the stored state. Use a constant-time comparison when your platform provides one.
4. Call the status endpoint. The status endpoint, not the callback query string, is the authoritative source of the result.

Do not mark an application record as signed merely because the browser reached this URL.

## 3. Read transaction status

```bash
curl --request GET \
  --header "X-IAM-eSign-Key: $IAM_ESIGN_API_KEY" \
  --header "Accept: application/json" \
  "$IAM_BASE_URL/api/esign/requests/5c8e9b5e-33ee-4d79-9aa4-84d0bb08dd0e"
```

Example completed response:

```json
{
  "transaction_id": "5c8e9b5e-33ee-4d79-9aa4-84d0bb08dd0e",
  "client_reference": "approval-12345",
  "status": "completed",
  "signer_name": "A. Example"
}
```

The normal statuses are:

| Status | Meaning |
| --- | --- |
| `awaiting_signer` | A request was created and its handoff has not yet been used. |
| `awaiting_response` | The browser entered the signing journey and IAM is waiting for C-DAC's response. |
| `completed` | IAM validated the response and the signed document is ready for download. |
| `failed` | Signing could not be completed. The response may include `failure_reason`. |

Treat unrecognized statuses as not completed so a future server-side status does not accidentally grant download access.

## 4. Download the signed document

Only download after a fresh status response reports `completed`:

```bash
curl --request GET \
  --header "X-IAM-eSign-Key: $IAM_ESIGN_API_KEY" \
  --output signed-approval.pdf \
  "$IAM_BASE_URL/api/esign/requests/5c8e9b5e-33ee-4d79-9aa4-84d0bb08dd0e/download"
```

The response is the document's binary content. Stream it through your backend with a safe attachment filename and an appropriate content type. Never expose a general-purpose endpoint that downloads an arbitrary transaction ID without confirming that the current browser session owns that transaction.

## Errors and recovery

IAM uses standard HTTP failure responses. Preserve the status code for application diagnostics, but present user-safe error messages rather than raw upstream response bodies.

| Situation | Expected response | Application action |
| --- | --- | --- |
| Missing, invalid, revoked, or wrong-client API key | `401` or `403` | Check the header and key status; do not reveal another client's transaction details. |
| Missing eSign scope | `403` | Enable the required scope for the OAuth client in IAM. |
| Invalid upload, mode, completion URL, or request field | `422` | Show a validation error. A completion URL that does not match the OAuth client's configured eSign Completion URL is rejected. |
| Handoff already used or expired | `410` | Start a new signing request with a new client reference. |
| Download before completion | `409` | Recheck status later; do not treat it as a signed document. |
| Failed transaction | `200` status payload with `status: "failed"` | Display an appropriate failure flow and retain diagnostic information only where permitted. |

## Security checklist

- Keep eSign API keys, the original uploaded file, and state values server-side.
- Generate state with a cryptographically secure random generator and bind it to the current browser session plus the business record being signed.
- Register a fixed HTTPS completion URL. Never take `completion_url` from untrusted browser input.
- Verify both transaction ownership and state on completion; then request status from IAM.
- Use a fresh status result before allowing a download or updating a business record to signed.
- Use the browser only for the handoff redirect. Perform create, status, and download requests from your backend.
- Log transaction IDs and HTTP outcomes carefully, but do not log API keys, C-DAC request XML, or signed-document contents.

## Laravel users

Laravel applications using this package can configure `IAM_ESIGN_API_KEY` and use `Nagaland\IamClient\Services\EsignBrokerClient` instead of manually issuing the HTTP requests. See the package README for the Laravel controller and route example.
