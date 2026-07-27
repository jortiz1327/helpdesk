import { useState, useEffect } from 'react'
import { api } from '../api.js'
import { useToast } from '../App.jsx'
import SedeSelect from './SedeSelect.jsx'

/*
 * Campo «Sede» autocontenido para la ficha del contacto (en el ticket y en
 * Contactos): pinta el selector, guarda solo al cambiar y avisa. Solo hay que
 * pasarle el id del contacto y su sede actual.
 */
export default function SedeField({ contactId, value }) {
  const toast = useToast()
  const [sede, setSede] = useState(value ?? null)
  useEffect(() => { setSede(value ?? null) }, [contactId, value])

  const save = async (v) => {
    setSede(v)
    const r = await api.saveContact(contactId, { sede_id: v || '' })
    if (!r.ok) toast(r.error || 'No se pudo guardar la sede', 'err')
  }

  if (!contactId) return null
  return <SedeSelect value={sede} onChange={save} />
}
