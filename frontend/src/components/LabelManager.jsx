import { useState } from 'react'
import { api } from '../api.js'
import { Icon } from '../icons.jsx'
import { useToast } from '../App.jsx'

export const LABEL_COLORS = ['#00a884', '#4a9bff', '#f4b740', '#f25c54', '#a06bff', '#ff8a3d', '#25d366', '#8696a0']

export default function LabelManager({ labels, onClose, onChanged }) {
  const toast = useToast()
  const [name, setName] = useState('')
  const [color, setColor] = useState(LABEL_COLORS[0])
  const [list, setList] = useState(labels)
  const [dragIdx, setDragIdx] = useState(null)
  const [overIdx, setOverIdx] = useState(null)

  const reload = () => api.listLabels().then((d) => { setList(d.labels || []); onChanged?.() })

  // Reordenar arrastrando: mueve de 'from' a 'to' y persiste el nuevo orden.
  const move = (from, to) => {
    if (from == null || to == null || from === to) return
    const next = [...list]
    const [it] = next.splice(from, 1)
    next.splice(to, 0, it)
    setList(next)
    api.reorderLabels(next.map((l) => l.id)).then((r) => { if (r.ok) onChanged?.(); else { toast('No se pudo reordenar', 'err'); reload() } })
  }
  const create = async () => {
    if (!name.trim()) return
    const r = await api.createLabel(name.trim(), color)
    if (r.ok) { setName(''); reload(); toast('Etiqueta creada') } else toast(r.error || 'Error', 'err')
  }
  const del = async (id) => { const r = await api.deleteLabel(id); if (r.ok) { reload(); toast('Etiqueta eliminada') } }

  return (
    <div className="modal-bg" onClick={(e) => e.target.classList.contains('modal-bg') && onClose()}>
      <div className="modal" style={{ maxWidth: 440 }}>
        <div className="modal-head"><h3>Gestionar etiquetas</h3><button className="x" onClick={onClose}>×</button></div>
        <div className="modal-body">
          <label className="field"><span className="lbl">Nueva etiqueta</span>
            <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Ej. Cliente VIP" onKeyDown={(e) => e.key === 'Enter' && create()} />
          </label>
          <div className="field">
            <span className="lbl">Color</span>
            <div className="color-row">
              {LABEL_COLORS.map((c) => <div key={c} className={`color-dot ${color === c ? 'sel' : ''}`} style={{ background: c }} onClick={() => setColor(c)} />)}
            </div>
          </div>
          <button className="btn" style={{ width: '100%', justifyContent: 'center' }} onClick={create}><Icon.plus /> Crear etiqueta</button>
          <div className="lm-list">
            {list.length === 0 && <p className="muted">No hay etiquetas todavía.</p>}
            {list.length > 1 && <p className="hint" style={{ margin: '4px 0 8px' }}>Arrastra ⠿ para cambiar el orden (afecta a las columnas del Kanban).</p>}
            {list.map((l, i) => (
              <div key={l.id}
                className={`lm-row ${dragIdx === i ? 'dragging' : ''} ${overIdx === i && dragIdx !== i ? 'over' : ''}`}
                draggable
                onDragStart={(e) => { e.dataTransfer.setData('text/plain', String(i)); e.dataTransfer.effectAllowed = 'move'; setDragIdx(i) }}
                onDragOver={(e) => { e.preventDefault(); setOverIdx(i) }}
                onDragLeave={() => setOverIdx((o) => (o === i ? null : o))}
                onDrop={(e) => { const from = parseInt(e.dataTransfer.getData('text/plain'), 10); move(Number.isNaN(from) ? dragIdx : from, i); setDragIdx(null); setOverIdx(null) }}
                onDragEnd={() => { setDragIdx(null); setOverIdx(null) }}
              >
                <span className="lm-grip" title="Arrastrar para reordenar">⠿</span>
                <span className="lbl-dot" style={{ background: l.color }} />
                <span className="lm-name">{l.name}</span>
                <button className="btn ghost sm" style={{ color: 'var(--danger)' }} onClick={() => del(l.id)}>Eliminar</button>
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
