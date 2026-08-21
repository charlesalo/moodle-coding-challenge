// Shows the normalised values, so capitalisation and lowercasing are visible,
// and the specific reason each failing row was rejected.
export default function RecordTable({ records }) {
  if (records.length === 0) {
    return <p className="muted">This file contains no data rows.</p>
  }

  return (
    <div className="table-scroll">
      <table>
        <thead>
          <tr>
            <th scope="col">Line</th>
            <th scope="col">Name</th>
            <th scope="col">Surname</th>
            <th scope="col">Email</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          {records.map((record) => (
            <tr key={record.line} className={record.valid ? 'row-valid' : 'row-error'}>
              <td>{record.line}</td>
              <td>{record.name || <span className="muted">—</span>}</td>
              <td>{record.surname || <span className="muted">—</span>}</td>
              <td>{record.email || <span className="muted">—</span>}</td>
              <td>
                {record.valid ? (
                  'Valid'
                ) : (
                  <>
                    <strong>Error</strong>
                    <ul className="errors">
                      {record.errors.map((message) => (
                        <li key={message}>{message}</li>
                      ))}
                    </ul>
                  </>
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
