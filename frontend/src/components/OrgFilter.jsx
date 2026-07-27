import { useState, useEffect } from 'react'
import { api } from '../api.js'
import Select from './Select.jsx'

/*
 * Filtro de la bandeja por ORGANIZACIÓN. Se puede elegir un grupo, una marca o una
 * sede; el valor es «grupo:ID» / «marca:ID» / «sede:ID» y el backend trae los tickets
 * de todo ese subárbol (p. ej. un grupo = todas las sedes de todas sus marcas).
 */
export default function OrgFilter({ value, onChange }) {
  const [opts, setOpts] = useState([{ value: 'all', label: 'Toda la organización' }])

  useEffect(() => {
    api.orgTree().then((d) => {
      const o = [{ value: 'all', label: 'Toda la organización' }]
      ;(d.grupos || []).forEach((g) => {
        o.push({ value: `grupo:${g.id}`, label: g.name })
        g.marcas.forEach((m) => {
          o.push({ value: `marca:${m.id}`, label: `${g.name} · ${m.name}` })
          m.sedes.forEach((s) => o.push({ value: `sede:${s.id}`, label: `${g.name} · ${m.name} · ${s.name}` }))
        })
      })
      setOpts(o)
    })
  }, [])

  // Si no hay ningún grupo creado, no tiene sentido mostrar el filtro (ni su etiqueta).
  if (opts.length <= 1) return null
  return (
    <div className="field"><span className="lbl">Organización</span>
      <Select block value={value || 'all'} onChange={onChange} options={opts} />
    </div>
  )
}
