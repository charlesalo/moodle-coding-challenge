// The API lives next to the built app, so relative URLs work both behind the
// Vite dev proxy and when Apache serves everything from one origin.
const ENDPOINTS = {
  preview: 'api/preview.php',
  import: 'api/import.php',
}

async function post(url, body) {
  let response
  try {
    response = await fetch(url, { method: 'POST', body })
  } catch {
    throw new Error('Could not reach the server. Is Apache running?')
  }

  let payload
  try {
    payload = await response.json()
  } catch {
    // A PHP fatal error or a misconfigured server returns HTML, not JSON.
    throw new Error(`The server returned an unexpected response (HTTP ${response.status}).`)
  }

  if (!response.ok) {
    const error = new Error(payload.error || `Request failed (HTTP ${response.status}).`)
    error.report = payload.report
    throw error
  }

  return payload
}

export function previewFile(file) {
  const body = new FormData()
  body.append('file', file)

  return post(ENDPOINTS.preview, body)
}

export function importToken(token) {
  const body = new FormData()
  body.append('token', token)

  return post(ENDPOINTS.import, body)
}
