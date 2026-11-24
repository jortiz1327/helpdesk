import { useEffect, useMemo, useRef, useState } from "react";
import { Icon } from "../icons.jsx";
import { VERSIONS } from "../versions.js";

/*
 * Ventana de Novedades. Se lee por SERIES (1.6, 1.5…) y, dentro de cada una, por SUBVERSIÓN (1.6.4, 1.6.3…).
 * Los datos salen de versions.js; aquí solo se presentan. Teclado: ↑/↓ cambia de subversión, ←/→ de serie, Esc cierra.
 */

const serieDe = (v) => v.split(".").slice(0, 2).join(".");

// «Nuevo botón «Insertar»: pasa el borrador…» → título en negrita (lo anterior a «: ») y texto. Sin dos puntos cortos, todo es texto.
function partir(txt) {
    const m = txt.match(/^(.{6,70}?):\s+(.+)$/s);
    return m && !/[.!?]$/.test(m[1]) ? { t: m[1], d: m[2].charAt(0).toUpperCase() + m[2].slice(1) } : { t: null, d: txt };
}

function Tarjetas({ tipo, items }) {
    if (!items?.length) return null;
    const mejoras = tipo === "mejoras";
    return (
        <section className={`nv-bloque ${tipo}`}>
            <header className="nv-bloque-h">
                <span className="nv-bloque-ic">{mejoras ? <Icon.star /> : <Icon.check />}</span>
                <h4>{mejoras ? "Novedades" : "Arreglos"}</h4>
                <span className="nv-bloque-n">{items.length}</span>
            </header>
            <ul className="nv-cards">
                {items.map((it, i) => {
                    const { t, d } = partir(it);
                    return (
                        <li className="nv-card" key={i} style={{ "--i": i }}>
                            <span className="nv-card-mark" aria-hidden="true" />
                            <div>
                                {t && <b>{t}</b>}
                                <span>{d}</span>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}

export default function VersionModal({ onClose }) {
    const series = useMemo(() => {
        const m = new Map();
        VERSIONS.forEach((v) => {
            const s = serieDe(v.version);
            if (!m.has(s)) m.set(s, []);
            m.get(s).push(v);
        });
        return [...m.entries()].map(([serie, lista]) => ({ serie, lista }));
    }, []);

    const [sel, setSel] = useState(VERSIONS[0].version);
    const [serie, setSerie] = useState(serieDe(VERSIONS[0].version));
    const cuerpo = useRef(null);
    const actual = VERSIONS.find((v) => v.version === sel) || VERSIONS[0];
    const lista = series.find((s) => s.serie === serie)?.lista || [];
    const total = (v) => (v.mejoras?.length || 0) + (v.arreglos?.length || 0);

    const elegir = (v) => {
        setSel(v.version);
        setSerie(serieDe(v.version));
        if (cuerpo.current) cuerpo.current.scrollTop = 0;
    };
    const irSerie = (dir) => {
        const i = series.findIndex((s) => s.serie === serie) + dir;
        if (i < 0 || i >= series.length) return;
        elegir(series[i].lista[0]);
    };
    const irSub = (dir) => {
        const i = lista.findIndex((v) => v.version === sel) + dir;
        if (i >= 0 && i < lista.length) elegir(lista[i]);
    };

    useEffect(() => {
        const h = (e) => {
            if (e.key === "Escape") onClose();
            else if (e.key === "ArrowDown") { e.preventDefault(); irSub(1); }
            else if (e.key === "ArrowUp") { e.preventDefault(); irSub(-1); }
            else if (e.key === "ArrowRight") irSerie(1);
            else if (e.key === "ArrowLeft") irSerie(-1);
        };
        document.addEventListener("keydown", h);
        return () => document.removeEventListener("keydown", h);
    });

    const esUltima = actual.version === VERSIONS[0].version;

    return (
        <div className="modal-bg" onClick={(e) => e.target.classList.contains("modal-bg") && onClose()}>
            <div className="nv-win" role="dialog" aria-modal="true" aria-labelledby="nv-titulo">
                <aside className="nv-aside">
                    <div className="nv-aside-top">
                        <span className="nv-pill"><Icon.star /> Novedades</span>
                        <h3 id="nv-titulo">Qué hay de nuevo en Helpdesk</h3>
                    </div>

                    <div className="nv-series" role="tablist" aria-label="Series de versiones">
                        {series.map((s) => (
                            <button
                                key={s.serie}
                                role="tab"
                                aria-selected={s.serie === serie}
                                className={`nv-serie${s.serie === serie ? " on" : ""}`}
                                onClick={() => elegir(s.lista[0])}
                            >
                                {s.serie}
                            </button>
                        ))}
                    </div>

                    <ol className="nv-linea" aria-label={`Versiones ${serie}`}>
                        {lista.map((v, i) => (
                            <li key={v.version}>
                                <button
                                    className={`nv-item${v.version === sel ? " on" : ""}`}
                                    onClick={() => elegir(v)}
                                    aria-current={v.version === sel ? "true" : undefined}
                                >
                                    <span className="nv-dot" aria-hidden="true" />
                                    <span className="nv-item-t">
                                        <b>v{v.version}</b>
                                        {v.version === VERSIONS[0].version && <em>Actual</em>}
                                    </span>
                                    <span className="nv-item-d">{v.date} · {total(v)} cambios</span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </aside>

                <section className="nv-main">
                    <button className="nv-x" onClick={onClose} aria-label="Cerrar"><Icon.x /></button>

                    <div className="nv-scroll" ref={cuerpo}>
                        <div className="nv-contenido" key={actual.version}>
                            <header className="nv-hero">
                                <div className="nv-ver">
                                    <span className="nv-ver-v">v{actual.version}</span>
                                    {esUltima && <span className="nv-tag">Versión actual</span>}
                                </div>
                                <div className="nv-meta">
                                    <span>{actual.date}</span>
                                    <i aria-hidden="true" />
                                    <span><b>{total(actual)}</b> cambios</span>
                                    {actual.arreglos?.length > 0 && <><i aria-hidden="true" /><span>{actual.arreglos.length} arreglos</span></>}
                                </div>
                                {actual.resumen && <p className="nv-resumen">{actual.resumen}</p>}
                            </header>

                            <Tarjetas tipo="mejoras" items={actual.mejoras} />
                            <Tarjetas tipo="arreglos" items={actual.arreglos} />
                        </div>
                    </div>

                    <footer className="nv-foot">
                        <span className="nv-hint">
                            <kbd>↑</kbd><kbd>↓</kbd> subversión <kbd>←</kbd><kbd>→</kbd> serie
                        </span>
                        <button className="btn" onClick={onClose}>Entendido</button>
                    </footer>
                </section>
            </div>
        </div>
    );
}
