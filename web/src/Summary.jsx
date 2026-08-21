// Mirrors the summary block the brief illustrates.
export default function Summary({ report }) {
  const rows = [
    ['Users found', report.found],
    ['Valid', report.valid],
    ['Invalid', report.invalid],
  ]

  if (!report.dryRun) {
    rows.push(['Imported', report.imported], ['Skipped', report.skipped])
  }

  return (
    <dl className="summary">
      {rows.map(([label, value]) => (
        <div key={label} className="summary-row">
          <dt>{label}:</dt>
          <dd>{value}</dd>
        </div>
      ))}
    </dl>
  )
}
