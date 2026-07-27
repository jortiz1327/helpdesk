import { useState, useEffect } from 'react'
import { api } from '../api.js'
import Select from './Select.jsx'

/*
 * Selector de la SEDE de un contacto. Carga el árbol de organización y ofrece las
 * sedes activas etiquetadas como «Grupo · Marca · Sede». value = sede_id (o '').
 */
export default function SedeSelect({ value, onChange, block = true }) {
  const [opts, setOpts] = useState([{ value: '', label: '— Sin sede —' }])

  useEffect(() => {
    api.orgTree().then((d) => {
      const o = [{ value: '', label: '— Sin sede —' }]
      ;(d.grupos || []).forEach((g) => {
        if (!g.active) return
        g.marcas.forEach((m) => {
          if (!m.active) return
          m.sedes.forEach((s) => {
            if (!s.active) return
            o.push({ value: String(s.id), label: `${g.name} · ${m.name} · ${s.name}` })
          })
        })
      })
      setOpts(o)
    })
  }, [])

  return <Select block={block} value={value ? String(value) : ''} onChange={(v) => onChange(v ? Number(v) : null)} options={opts} />
}
