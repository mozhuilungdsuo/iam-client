# IAM eSign Broker Integration Guide

This guide describes how any server-side application can integrate with the Nagaland IAM eSign broker. It is framework- and language-neutral: use its HTTPS API from Django, Express, Spring, .NET, Laravel, or another backend.

> The Laravel package offers `EsignBrokerClient` as a convenience wrapper. Applications that do not use Laravel can call the endpoints in this document directly.

## What IAM handles

IAM is the broker between an application and C-DAC eSign. It owns the C-DAC private key, creates the eSign XML, receives the C-DAC callback, and stores the signed document. An integrating application must not install the C-DAC private key or send request XML directly to C-DAC.

Your application is responsible for:

- retaining its eSign API key in a server-side secret store;
- uploading the document to IAM and directing the browser to the returned handoff URL;
- storing a transaction-to-browser-session association and a random state value;
- validating the callback, checking the final status, and serving the downloaded signed file.

## Prerequisites

Before making API calls, configure the OAuth client in IAM:

1. Allow `esign.request` and `esign.read` in the OAuth client's IAM configuration.
2. Set the application's completion URL in the OAuth client's **eSign Completion URL** field in IAM Admin. The value sent as `completion_url` must exactly match this field.
3. Generate an eSign API key from **IAM Admin → OAuth Clients**. IAM displays the plaintext key once; store it in the application's secret manager.
4. Use an HTTPS IAM URL and keep the API key on the server. Do not expose it to browser JavaScript.

The broker uses a static eSign API key rather than an IAM user login. A key can create, read, and download only transactions belonging to its OAuth client. Generating a replacement key invalidates the prior key immediately; an IAM administrator can revoke a key at any time.

## Integration flow

```text
Application backend  -- create request -->  IAM broker
Application browser  <-- handoff URL ------  IAM broker
Application browser  -- visit URL ------->  IAM / C-DAC signing journey
C-DAC                -- signed result --->  IAM broker
Application browser  <-- completion URL --  IAM broker
Application backend  -- status/download --> IAM broker
```

1. Load the OAuth client's eSign API key from a server-side secret store.
2. Generate a high-entropy `state` value and retain it alongside the browser session and the application record being signed.
3. Call the create endpoint with a unique, application-owned `client_reference` and the uploaded document.
4. Store the returned `transaction_id`, the random state, and the original filename in the browser session before redirecting the browser to `handoff_url`.
5. IAM's handoff page posts the request to C-DAC. The handoff URL is single-use and expires after 10 minutes.
6. IAM receives C-DAC's response, then redirects the browser to `completion_url` with `esign_transaction_id` and `state`.
7. Verify that the transaction belongs to the current browser session and compare state values using a constant-time comparison. Fetch the transaction status from IAM; do not accept a status supplied by the query string.
8. When the status is `completed`, call the download endpoint and stream the returned bytes to the user as a file.

## API base URL and authentication

Set `IAM_BASE_URL` to the IAM installation URL without a trailing slash. All API requests need the OAuth client's eSign API key and should request JSON responses:

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
| `mode` | No | `direct` (default) or `visible`. |
| `signature_field_name` | No | Optional field name for `direct` mode; maximum 120 characters. |
| `signer_name` | No | Optional signer name for `visible` mode; maximum 120 characters. |

A newly created request returns HTTP `201 Created` and a payload similar to:

```json
{
  "transaction_id": "5c8e9b5e-33ee-4d79-9aa4-84d0bb08dd0e",
  "client_reference": "approval-12345",
  "status": "awaiting_signer",
  "handoff_url": "https://iam.example.gov.in/esign/handoff/<single-use-token>"
}
```

Redirect the browser to `handoff_url` immediately. Do not fetch the URL from your backend: it begins the browser-facing signing flow.

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
