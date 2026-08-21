import { useState } from 'react'
import { previewFile, importToken } from './api.js'
import Summary from './Summary.jsx'
import RecordTable from './RecordTable.jsx'

// Three states, matching the flow in the brief:
//   upload -> preview -> result
const UPLOAD = 'upload'
const PREVIEW = 'preview'
const RESULT = 'result'

export default function App() {
  const [stage, setStage] = useState(UPLOAD)
  const [file, setFile] = useState(null)
  const [token, setToken] = useState(null)
  const [report, setReport] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  function reset() {
    setStage(UPLOAD)
    setFile(null)
    setToken(null)
    setReport(null)
    setError(null)
  }

  async function handlePreview(event) {
    event.preventDefault()
    if (!file || busy) return

    setBusy(true)
    setError(null)

    try {
      const { token: newToken, report: newReport } = await previewFile(file)
      setToken(newToken)
      setReport(newReport)
      setStage(PREVIEW)
    } catch (e) {
      // Surfaced in the UI, not just the console.
      setError(e.message)
    } finally {
      setBusy(false)
    }
  }

  async function handleImport() {
    if (!token || busy) return

    setBusy(true)
    setError(null)

    try {
      // Only the token is sent: the server re-reads and re-validates the file
      // it stored, so nothing editable in the browser reaches the database.
      const { report: newReport } = await importToken(token)
      setReport(newReport)
      setStage(RESULT)
    } catch (e) {
      setError(e.message)
      if (e.report) setReport(e.report)
    } finally {
      setBusy(false)
    }
  }

  return (
    <main>
      <h1>User Import</h1>

      {error && (
        <p className="alert" role="alert">
          {error}
        </p>
      )}

      {stage === UPLOAD && (
        <form onSubmit={handlePreview}>
          <p>Choose a CSV file with a <code>name,surname,email</code> header row.</p>
          <input
            type="file"
            accept=".csv,text/csv"
            onChange={(e) => setFile(e.target.files[0] ?? null)}
          />
          <div className="actions">
            <button type="submit" disabled={!file || busy}>
              {busy ? 'Checking…' : 'Parse and validate'}
            </button>
          </div>
        </form>
      )}

      {stage === PREVIEW && report && (
        <section>
          <h2>Preview</h2>
          <p className="muted">Nothing has been imported yet.</p>

          <Summary report={report} />
          {report.notices?.map((notice) => (
            <p key={notice} className="notice">{notice}</p>
          ))}

          <RecordTable records={report.records} />

          <div className="actions">
            <button type="button" onClick={handleImport} disabled={busy || report.valid === 0}>
              {busy ? 'Importing…' : `Import ${report.valid} user${report.valid === 1 ? '' : 's'}`}
            </button>
            <button type="button" className="secondary" onClick={reset} disabled={busy}>
              Choose a different file
            </button>
          </div>

          {report.valid === 0 && (
            <p className="muted">No valid records to import.</p>
          )}
        </section>
      )}

      {stage === RESULT && report && (
        <section>
          <h2>Import complete</h2>
          <p>
            Imported <strong>{report.imported}</strong> of {report.found} record
            {report.found === 1 ? '' : 's'}.
            {report.skipped > 0 && ` ${report.skipped} were skipped as already present.`}
          </p>

          <Summary report={report} />
          <RecordTable records={report.records} />

          <div className="actions">
            <button type="button" onClick={reset}>Import another file</button>
          </div>
        </section>
      )}
    </main>
  )
}
