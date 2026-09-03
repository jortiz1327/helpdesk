import {
    createContext,
    useContext,
    useState,
    useCallback,
    useRef,
    useEffect,
    useLayoutEffect,
} from "react";
import { Icon } from "./icons.jsx";
import { api, onUnauthorized, setToken } from "./api.js";
import Inbox from "./components/Inbox.jsx";
import Templates from "./components/Templates.jsx";
import Account from "./components/Account.jsx";
import Dashboard from "./components/Dashboard.jsx";
import SupportCenter from "./components/SupportCenter.jsx";
import NewTicket from "./components/NewTicket.jsx";
import SupportSettings from "./components/SupportSettings.jsx";
import Settings from "./components/Settings.jsx";   // config WhatsApp (accesible desde Campañas)
import Shifts from "./components/Shifts.jsx";
import Tickets from "./components/Tickets.jsx";
import Kanban from "./components/Kanban.jsx";
import Automations from "./components/Automations.jsx";
import BotResponses from "./components/BotResponses.jsx";
import Forms from "./components/Forms.jsx";
import Phonebook from "./components/Phonebook.jsx";
import Contacts from "./components/Contacts.jsx";
import Organizations from "./components/Organizations.jsx";
import Reports from "./components/Reports.jsx";
import Activity from "./components/Activity.jsx";
import SendCampaign from "./components/SendCampaign.jsx";
import CampaignDashboard from "./components/CampaignDashboard.jsx";
import CampaignEmailSettings from "./components/CampaignEmailSettings.jsx";
import Manuals from "./components/Manuals.jsx";
import WebNotifications from "./components/WebNotifications.jsx";
import NotificationBell from "./components/NotificationBell.jsx";
import MorningBriefing from "./components/MorningBriefing.jsx";
import AreaChooser from "./components/AreaChooser.jsx";
import Users from "./components/Users.jsx";
import Analytics from "./components/Analytics.jsx";
import { notifyActive, getNotify, fireNotification } from "./notify.js";
import {
    connectRealtime,
    disconnectRealtime,
    onTicketActivity,
} from "./realtime.js";
import Login from "./components/Login.jsx";
import logo from "./assets/logo.png";

// ---- Toast ----
const ToastCtx = createContext(() => {});
export const useToast = () => useContext(ToastCtx);

function ToastHost({ toasts, onClose }) {
    // Accesibilidad: cada aviso es una live-region para que el lector de pantalla lo
    // anuncie. Los errores interrumpen (alert/assertive); el resto espera un hueco
    // (status/polite). Sin esto, «Respuesta enviada» / «No se pudo enviar» pasaban mudos.
    return (
        <div className="toasts" role="region" aria-label="Notificaciones">
            {toasts.map((t) => (
                <div key={t.id} className={`toast ${t.kind} ${t.action ? 'has-action' : ''}`}
                    role={t.kind === 'err' ? 'alert' : 'status'}
                    aria-live={t.kind === 'err' ? 'assertive' : 'polite'}>
                    <span>{t.msg}</span>
                    {t.action && (
                        <button className="toast-action" onClick={() => { t.action.fn(); onClose?.(t.id); }}>
                            {t.action.label}
                        </button>
                    )}
                </div>
            ))}
        </div>
    );
}

// ---- Confirmación ----
const ConfirmCtx = createContext(() => Promise.resolve(false));
export const useConfirm = () => useContext(ConfirmCtx);

function ConfirmDialog({ opts, onClose }) {
    useEffect(() => {
        const h = (e) => {
            if (e.key === "Escape") onClose(false);
            if (e.key === "Enter") onClose(true);
        };
        document.addEventListener("keydown", h);
        return () => document.removeEventListener("keydown", h);
    }, [onClose]);
    return (
        <div
            className="modal-bg"
            onClick={(e) =>
                e.target.classList.contains("modal-bg") && onClose(false)
            }
        >
            <div className="modal confirm-box">
                <div className={`confirm-ico ${opts.danger ? "danger" : ""}`}>
                    {opts.danger ? <Icon.trash /> : <Icon.warn />}
                </div>
                <h3>{opts.title || "¿Confirmar?"}</h3>
                {opts.message && <p>{opts.message}</p>}
                <div className="confirm-actions">
                    <button
                        className="btn ghost"
                        onClick={() => onClose(false)}
                    >
                        {opts.cancelText || "Cancelar"}
                    </button>
                    <button
                        className={`btn ${opts.danger ? "danger" : ""}`}
                        onClick={() => onClose(true)}
                        autoFocus
                    >
                        {opts.confirmText || "Aceptar"}
                    </button>
                </div>
            </div>
        </div>
    );
}

const APP_VERSION = "1.0.0";

/*
 * La plataforma tiene dos ÁREAS: Helpdesk y Campañas. El superadmin puede cambiar
 * entre ellas con el selector de arriba; un rol de un área entra directo a la suya.
 * Cada entrada declara el PERMISO que exige (config/rbac.php); el backend valida
 * igualmente, esto solo decide qué se PINTA.
 */
// Contactos y Agenda: transversales, aparecen en las DOS áreas (soporte y campañas).
// Se gatean por contacts.access (que tienen agentes y campañas), no por campaigns.
const CONTACTS_GROUP = {
    title: "Contactos",
    items: [
        {
            key: "contacts",
            label: "Contactos",
            icon: Icon.user,
            perm: "contacts.access",
            color: "#f4b740",
        },
        {
            // La agenda es la de WhatsApp: en Helpdesk no aporta, así que solo
            // sale en Campañas. Sigue siendo vista válida (permisos + enlace).
            key: "phonebook",
            label: "Agenda de contactos",
            icon: Icon.calendar,
            perm: "contacts.access",
            color: "#54a0ff",
            hideInAreas: ["helpdesk"],
        },
        {
            key: "organizations",
            label: "Organización",
            icon: Icon.building,
            perm: "contacts.access",
            color: "#12925a",
        },
    ],
};

const AREAS = [
    {
        key: "helpdesk",
        label: "Helpdesk",
        icon: Icon.headset,
        perm: "helpdesk.access",
        home: "support",
        groups: [
            {
                title: "Soporte",
                items: [
                    {
                        key: "support",
                        label: "Centro de Soporte",
                        icon: Icon.headset,
                        perm: "helpdesk.access",
                        color: "#2563eb",
                    },
                    {
                        key: "tickets",
                        label: "Gestión de tickets",
                        icon: Icon.ticket,
                        perm: "helpdesk.access",
                        color: "#3b82f6",
                    },
                    {
                        // No sale en el menú: crear es una ACCIÓN, ya está como botón
                        // «Nuevo ticket» en el Centro de Soporte y en Gestión de
                        // tickets. Sigue siendo vista válida (permisos + enlace).
                        key: "ticket_new",
                        label: "Nuevo ticket",
                        icon: Icon.plus,
                        perm: "tickets.create",
                        color: "#10b981",
                        hidden: true,
                    },
                    {
                        key: "shifts",
                        label: "Turnos",
                        icon: Icon.calendar,
                        perm: "helpdesk.access",
                        color: "#7c3aed",
                    },
                    {
                        // No sale en el menú: se abre desde una pestaña dentro del
                        // Centro de Soporte (Resumen | Informes). Sigue siendo una
                        // vista válida (permisos + enlace directo) gracias a `hidden`.
                        key: "reports",
                        label: "Informes",
                        icon: Icon.chart,
                        perm: "analytics.view",
                        color: "#12925a",
                        hidden: true,
                    },
                    {
                        key: "support_cfg",
                        label: "Configuración",
                        icon: Icon.settings,
                        perm: "support.config",
                        color: "#8696a0",
                    },
                ],
            },
            CONTACTS_GROUP,
        ],
    },
    {
        key: "campaigns",
        label: "Campañas",
        icon: Icon.send,
        perm: "campaigns.access",
        home: "analytics",
        groups: [
            // Analíticas es la página principal de Campañas y va arriba del todo.
            {
                title: "Resumen",
                items: [
                    {
                        key: "analytics",
                        label: "Analíticas",
                        icon: Icon.chart,
                        perm: "analytics.view",
                        color: "#f59e0b",
                    },
                ],
            },
            {
                title: "Difusiones",
                items: [
                    {
                        key: "campaign_dash",
                        label: "Panel de campañas",
                        icon: Icon.carousel,
                        perm: "campaigns.access",
                        color: "#a0d911",
                    },
                    {
                        key: "campaign_send",
                        label: "Enviar campaña",
                        icon: Icon.send,
                        perm: "campaigns.send",
                        color: "#2dd4bf",
                    },
                    {
                        key: "templates",
                        label: "Plantillas",
                        icon: Icon.templates,
                        perm: "templates.manage",
                        color: "#ff9f43",
                    },
                    {
                        key: "forms",
                        label: "Formularios",
                        icon: Icon.forms,
                        perm: "forms.manage",
                        color: "#e056fd",
                    },
                ],
            },
            // A diferencia de Helpdesk, Campañas SÍ muestra el inbox de WhatsApp: aquí es «Chat en vivo».
            {
                title: "Conversaciones",
                items: [
                    {
                        key: "inbox",
                        label: "Chat en vivo",
                        icon: Icon.message,
                        perm: "campaigns.access",
                        color: "#25d366",
                    },
                    {
                        key: "automations",
                        label: "Automatizaciones",
                        icon: Icon.bolt,
                        perm: "automations.manage",
                        color: "#ff7ab6",
                    },
                ],
            },
            {
                // La config de WhatsApp (números, token, WABA, webhook) es lo que da vida
                // a todo Campañas: aquí accesible directa, sin saltar a Helpdesk. Solo superadmin.
                title: "Ajustes",
                items: [
                    {
                        key: "wa_config",
                        label: "Configuración",
                        icon: Icon.whatsappCfg,
                        perm: "support.config",
                        color: "#12925a",
                    },
                    {
                        key: "campaign_email_cfg",
                        label: "Correo de campañas",
                        icon: Icon.mail,
                        perm: "support.config",
                        color: "#6c8cff",
                    },
                ],
            },
            CONTACTS_GROUP,
        ],
    },
];

// Administración: transversal, aparece en cualquier área (para quien pueda).
const ADMIN_GROUP = {
    title: "Administración",
    items: [
        {
            key: "users",
            label: "Usuarios",
            icon: Icon.user,
            perm: "users.manage",
            color: "#6c8cff",
        },
        {
            key: "activity",
            label: "Acciones",
            icon: Icon.activity,
            perm: "activity.view",
            color: "#12b886",
        },
        // La configuración de WhatsApp (números, webhook…) ya no cuelga de aquí como
        // pantalla propia: vive dentro de «Configuración → WhatsApp» (solo superadmin).
    ],
};

// Ayuda: transversal (aparece en todas las áreas). Sin `perm`: lo ve cualquier
// usuario con sesión; los manuales que se ofrecen dentro ya se filtran por rol.
const HELP_GROUP = {
    title: "Ayuda",
    items: [
        {
            key: "help",
            label: "Manuales",
            icon: Icon.doc,
            color: "#8696a0",
        },
    ],
};

const NAV = [...AREAS.flatMap((a) => a.groups), HELP_GROUP, ADMIN_GROUP].flatMap(
    (g) => g.items,
);

/*
 * LA VISTA VIVE EN LA URL (`/shifts`).
 *
 * Antes era solo un estado de React, así que cualquier F5 te devolvía al Centro de
 * Soporte y perdías dónde estabas. En la URL sale gratis, además de aguantar la
 * recarga: el botón «atrás» del navegador vuelve a la pantalla anterior y se puede
 * pasar un enlace a un compañero.
 *
 * Son rutas LIMPIAS, sin `#`. Se puede porque el servidor ya devuelve la SPA en
 * cualquier ruta que no sea de la API (`Route::fallback` en routes/web.php); sin
 * eso, recargar en `/shifts` daría un 404 del servidor antes de que la app llegue
 * a arrancar.
 *
 * Se usa la MISMA clave que el menú, sin traducirla: un diccionario de rutas
 * bonitas habría que acordarse de ampliarlo con cada pantalla nueva, y el día que
 * se olvide, esa pantalla deja de aguantar la recarga sin que nadie se entere.
 *
 * Las que no salen en el menú (cuenta, avisos, kanban…) se listan aparte: si la
 * URL trae cualquier otra cosa se ignora y se entra por la puerta de siempre.
 */
const VISTAS_SUELTAS = ["account", "webpush", "kanban", "bot_responses", "dashboard"];
const esVista = (k) => NAV.some((n) => n.key === k) || VISTAS_SUELTAS.includes(k);
/*
 * La app de agentes vive bajo /agentes: la raíz `/` es el PORTAL del cliente
 * (main.jsx decide cuál montar). Aquí se quita ese prefijo para leer la vista y se
 * vuelve a poner al escribir la URL, así el resto del código sigue hablando de
 * «tickets», «shifts»… sin saber del prefijo.
 */
const AG = "/agentes";
const vistaDeUrl = () => {
    const k = window.location.pathname.replace(/^\/agentes/, "").replace(/^\/+|\/+$/g, "");
    if (esVista(k)) return k;
    // Enlaces viejos con `#/shifts`: se aceptan una vez y se limpian solos.
    const viejo = (window.location.hash || "").replace(/^#\/?/, "");
    return esVista(viejo) ? viejo : null;
};

// ¿Puede el usuario ver esta entrada del menú?
const allows = (user, item) =>
    !item.perm || (user?.permissions || []).includes(item.perm);
// Áreas a las que el usuario tiene acceso
const areasFor = (user) => AREAS.filter((a) => allows(user, a));
// ¿A qué área pertenece una vista? (account/usuarios no pertenecen a ninguna)
const areaOfView = (v) =>
    AREAS.find((a) => a.groups.some((g) => g.items.some((i) => i.key === v)));

function initialsOf(user) {
    const n = (user?.name || user?.email || "HD").trim();
    const parts = n.split(/\s+/).filter(Boolean);
    return (
        parts
            .slice(0, 2)
            .map((w) => w[0])
            .join("") || "HD"
    ).toUpperCase();
}

/*
 * La antigua BARRA SUPERIOR (migas + tema + menú de usuario) se ha eliminado.
 * Ahora cada pantalla lleva su propio título y la cuenta vive al pie del sidebar:
 * más aire vertical y una sola zona de navegación en lugar de dos.
 */

export default function App() {
    const [auth, setAuth] = useState({ state: "loading", user: null });
    const [view, setView] = useState(() => vistaDeUrl() || "support");
    // Pestaña con la que abrir «Gestión de tickets» al saltar desde otro sitio.
    const [ticketsTab, setTicketsTab] = useState("tickets");
    // Ticket que hay que abrir nada más llegar (al pinchar uno de los recientes).
    const [ticketAbierto, setTicketAbierto] = useState(null);
    // Filtro de organización preaplicado al saltar desde la pantalla de Organización.
    const [orgFiltro, setOrgFiltro] = useState(null);
    const [activeArea, setActiveArea] = useState(
        () => localStorage.getItem("active_area") || "helpdesk",
    );
    // ¿Ya eligió área? Se recuerda (localStorage) para NO volver a preguntar en cada F5;
    // se reinicia al cerrar sesión. Así una recarga te devuelve a tu última área.
    const [chooserDone, setChooserDone] = useState(
        () => localStorage.getItem("chooser_done") === "1",
    );
    const [unread, setUnread] = useState(0);
    const [toasts, setToasts] = useState([]);
    const [expanded, setExpanded] = useState(
        () => localStorage.getItem("rail_expanded") === "1",
    );
    const [railTip, setRailTip] = useState(null); // tooltip fijo del rail colapsado: { label, top, left, color }
    const [theme, setTheme] = useState(
        () => localStorage.getItem("theme") || "light",
    );
    const [openTarget, setOpenTarget] = useState(null);
    const idRef = useRef(0);

    const toggleRail = () =>
        setExpanded((e) => {
            localStorage.setItem("rail_expanded", e ? "0" : "1");
            return !e;
        });

    useEffect(() => {
        document.documentElement.setAttribute("data-theme", theme);
        localStorage.setItem("theme", theme);
    }, [theme]);
    const toggleTheme = () =>
        setTheme((t) => (t === "dark" ? "light" : "dark"));

    const openConversation = (id) => {
        setOpenTarget(id);
        setView("inbox");
    };

    // `action` (opcional): { label, fn } añade un botón —p. ej. «Deshacer»— y da más
    // tiempo antes de que el aviso desaparezca.
    const toast = useCallback((msg, kind = "ok", action = null) => {
        const id = ++idRef.current;
        setToasts((t) => [...t, { id, msg, kind, action }]);
        setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), action ? 6500 : 2800);
    }, []);
    const cerrarToast = useCallback((id) => setToasts((t) => t.filter((x) => x.id !== id)), []);

    const [confirmState, setConfirmState] = useState(null);
    const confirm = useCallback(
        (opts) =>
            new Promise((resolve) =>
                setConfirmState({
                    opts: typeof opts === "string" ? { message: opts } : opts,
                    resolve,
                }),
            ),
        [],
    );
    const closeConfirm = useCallback(
        (val) => {
            confirmState?.resolve(val);
            setConfirmState(null);
        },
        [confirmState],
    );

    useEffect(() => {
        onUnauthorized(() => {
            setToken("");
            setAuth({ state: "out", user: null });
        });
        api.me()
            .then((d) => {
                setAuth(
                    d.authenticated
                        ? { state: "in", user: d.user }
                        : { state: "out", user: null },
                );
            })
            .catch(() => setAuth({ state: "out", user: null }));
    }, []);

    /*
     * TIEMPO REAL. Se conecta el websocket al entrar y se corta al salir.
     * Aquí solo se muestran los AVISOS; cada vista se refresca por su cuenta
     * suscribiéndose con onTicketActivity().
     */
    useEffect(() => {
        if (auth.state !== "in") return;
        connectRealtime();

        const off = onTicketActivity((e) => {
            if (e.action === "message") {
                toast(`💬 ${e.code}: el cliente ha respondido`);
                fireNotification(
                    `Nueva respuesta · ${e.code}`,
                    e.subject || "Un cliente ha escrito",
                    () => setView("tickets"),
                );
            } else if (e.action === "created") {
                toast(`🎫 Nuevo ticket ${e.code}`);
                fireNotification(
                    `Nuevo ticket · ${e.code}`,
                    e.subject || "",
                    () => setView("tickets"),
                );
            } else if (
                e.action === "assigned" &&
                e.assignedTo &&
                Number(e.assignedTo) === Number(auth.user?.id)
            ) {
                /*
                 * Solo se avisa a QUIEN recibe el ticket. Avisar a todos de cada
                 * asignación convertiría el aviso en ruido y dejaría de mirarse
                 * —que es justo lo que le pasa a los avisos que sobran—.
                 */
                toast(`👤 Te han asignado ${e.code}`);
                fireNotification(
                    `Te han asignado un ticket · ${e.code}`,
                    e.subject || "",
                    () => setView("tickets"),
                );
            }
        });

        return () => {
            off();
            disconnectRealtime();
        };
    }, [auth.state, toast, auth.user?.id]);

    // contador global de no leídos para el badge del sidebar (en cualquier vista)
    useEffect(() => {
        if (auth.state !== "in") return;
        let alive = true;
        const tick = () =>
            api
                .stats()
                .then((s) => {
                    if (alive && s && typeof s.unread !== "undefined")
                        setUnread(s.unread);
                })
                .catch(() => {});
        tick();
        const t = setInterval(tick, 15000);
        return () => {
            alive = false;
            clearInterval(t);
        };
    }, [auth.state]);

    // Avisos web (Nivel 1): detecta mensajes nuevos comparando last_time con unread
    const notifSeen = useRef(null); // Map contactId -> last_time, o null = sin sembrar
    useEffect(() => {
        if (auth.state !== "in") return;
        let alive = true;
        const tick = async () => {
            if (!notifyActive() || !getNotify().messages) {
                notifSeen.current = null;
                return;
            }
            const d = await api.listConversations("").catch(() => null);
            if (!alive || !d || !d.conversations) return;
            const prev = notifSeen.current;
            const cur = new Map();
            for (const c of d.conversations) cur.set(c.id, c.last_time);
            if (prev && !document.hasFocus()) {
                for (const c of d.conversations) {
                    if (Number(c.unread) !== 1) continue;
                    const before = prev.get(c.id);
                    if (
                        before === undefined ||
                        (c.last_time && c.last_time > before)
                    ) {
                        fireNotification(
                            c.name || "+" + c.wa_id,
                            c.last_message || "Nuevo mensaje",
                            () => openConversation(c.id),
                        );
                    }
                }
            }
            notifSeen.current = cur;
        };
        tick();
        const t = setInterval(tick, 12000);
        return () => {
            alive = false;
            clearInterval(t);
        };
    }, [auth.state]);

    // Si el usuario no tiene permiso para la vista actual, se le lleva a la HOME de su
    // primera área accesible (no a un ítem cualquiera del menú). Antes se cogía «la primera
    // vista que pasara el permiso», pero como `analytics.view` lo comparten las Analíticas de
    // Campañas y los Informes de Soporte (vista `reports`, oculta y antes en el menú), un
    // usuario de Campañas aterrizaba en los informes de SOPORTE. La home del área es lo suyo.
    // useLayoutEffect (no useEffect) para corregir la vista ANTES del pintado: así no se ve ni
    // un fotograma de la pantalla de soporte al entrar con un usuario que no es de soporte.
    useLayoutEffect(() => {
        if (auth.state !== "in" || view === "account") return;
        const item = NAV.find((n) => n.key === view);
        if (item && !allows(auth.user, item)) {
            const home = areasFor(auth.user)[0]?.home;
            setView(
                home ||
                    (NAV.find((n) => allows(auth.user, n) && !n.hidden) || {
                        key: "dashboard",
                    }).key,
            );
        }
    }, [auth.state, auth.user, view]);

    // El área activa sigue a la vista: si navegas (o un aviso te lleva) a una vista de
    // OTRA área, el menú lateral cambia solo. Si la vista es compartida (contactos,
    // agenda) y ya está en el área activa, no se cambia. account/usuarios no cambian.
    useEffect(() => {
        const cur = AREAS.find((a) => a.key === activeArea);
        const inCur =
            cur && cur.groups.some((g) => g.items.some((i) => i.key === view));
        if (inCur) return;
        const va = areaOfView(view);
        if (va && va.key !== activeArea) setActiveArea(va.key);
    }, [view]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        localStorage.setItem("active_area", activeArea);
    }, [activeArea]);

    /*
     * La URL sigue a la vista. La PRIMERA vez se reemplaza en vez de apilar: si no,
     * nada más entrar ya habría una entrada de historial de más y el «atrás» se
     * gastaría en volver a la misma pantalla.
     */
    const urlPuesta = useRef(false);
    useEffect(() => {
        const actual = window.location.pathname.replace(/^\/agentes/, "").replace(/^\/+|\/+$/g, "");
        // El `hash` se pisa siempre: así un enlace viejo `#/shifts` queda limpio.
        if (actual !== view || window.location.hash) {
            const url = AG + "/" + view;
            if (urlPuesta.current) window.history.pushState(null, "", url);
            else window.history.replaceState(null, "", url);
        }
        urlPuesta.current = true;
    }, [view]);

    /*
     * El ticket a abrir se olvida al salir de la pantalla. Si no, volver a «Gestión
     * de tickets» por el menú un rato después te reabriría aquel ticket de la nada,
     * porque el dato seguiría guardado aquí.
     */
    useEffect(() => {
        if (view !== "tickets") { setTicketAbierto(null); setOrgFiltro(null); }
    }, [view]);

    // Y la vista sigue a la URL, que es lo que hace funcionar «atrás» y «adelante».
    useEffect(() => {
        const alCambiar = () => {
            const v = vistaDeUrl();
            if (v) setView(v);
        };
        window.addEventListener("popstate", alCambiar);
        return () => window.removeEventListener("popstate", alCambiar);
    }, []);

    const logout = async () => {
        if (
            !(await confirm({
                title: "Cerrar sesión",
                message: "¿Seguro que quieres salir?",
                confirmText: "Cerrar sesión",
            }))
        )
            return;
        await api.logout();
        setAuth({ state: "out", user: null });
        setView("support");
        setChooserDone(false); // que vuelva a preguntar el área al reentrar
        localStorage.removeItem("chooser_done");
    };

    if (auth.state === "loading")
        return (
            <div className="boot">
                <div className="spinner" />
            </div>
        );
    if (auth.state === "out")
        return <Login onLogin={(user) => setAuth({ state: "in", user })} />;

    const can = (perm) => (auth.user?.permissions || []).includes(perm);
    const myAreas = areasFor(auth.user);

    // Bienvenida tipo «píldoras de Matrix»: solo si puede entrar a más de un área y aún no ha elegido.
    if (myAreas.length > 1 && !chooserDone) {
        return (
            <AreaChooser
                areas={myAreas}
                user={auth.user}
                onPick={(key) => {
                    const a = AREAS.find((x) => x.key === key) || AREAS[0];
                    setActiveArea(key);
                    setView(a.home);
                    setChooserDone(true);
                    localStorage.setItem("chooser_done", "1");
                }}
            />
        );
    }
    const areaKey = myAreas.some((a) => a.key === activeArea)
        ? activeArea
        : myAreas[0]?.key || "helpdesk";
    const area = AREAS.find((a) => a.key === areaKey) || AREAS[0];
    // Menú del área activa + Administración (transversal), filtrado por permisos.
    const navGroups = [...area.groups, HELP_GROUP, ADMIN_GROUP]
        .map((g) => ({
            ...g,
            items: g.items.filter(
                (n) =>
                    allows(auth.user, n) &&
                    !n.hidden &&
                    !(n.hideInAreas || []).includes(areaKey),
            ),
        }))
        .filter((g) => g.items.length);
    const viewLabel =
        view === "account"
            ? "Cuenta"
            : NAV.find((n) => n.key === view)?.label || "";

    return (
        <ToastCtx.Provider value={toast}>
            <ConfirmCtx.Provider value={confirm}>
                <div className="app">
                    {/* Recibimiento matutino: tickets pospuestos que han despertado. */}
                    <MorningBriefing
                        user={auth.user}
                        onOpen={(id) => {
                            setTicketsTab("tickets");
                            setTicketAbierto(id);
                            setView("tickets");
                        }}
                        onGoInbox={() => {
                            setTicketAbierto(null);
                            setTicketsTab("tickets");
                            setView("tickets");
                        }}
                    />
                    <nav className={`rail ${expanded ? "expanded" : ""}`}>
                        {/*
                          * MARCA. Desplegado se ve el logotipo entero; plegado, solo
                          * la marca dentro del cuadrado (un logotipo de 4:1 metido en
                          * 42 px no se lee). Se quitó el rótulo «HelpDesk»: el rail lo
                          * comparten las dos áreas, así que ponía el nombre de una
                          * mientras estabas en la otra. Qué área es ya lo dice el
                          * selector de justo debajo.
                          */}
                        <div className="rail-top">
                            {/* El logo abre el PORTAL PÚBLICO en otra pestaña (para verlo sin
                                salir de la app de agentes). El portal vive en la raíz «/». */}
                            <a className="rail-logo-link" href="/" target="_blank" rel="noopener"
                               title="Ver el portal público (nueva pestaña)">
                                {expanded ? (
                                    <img className="rail-logo" src={logo} alt="AEME Group" />
                                ) : (
                                    <div className="logo" title="AEME Group" />
                                )}
                            </a>
                        </div>

                        {/* Selector de área: solo si el usuario puede entrar a más de una (superadmin). */}
                        {myAreas.length > 1 && (
                            <div
                                className={`area-switch ${expanded ? "" : "rail-collapsed"}`}
                            >
                                <button
                                    className="area-cur"
                                    /*
                                     * Lleva a la pantalla de elección de área. Antes
                                     * abría un desplegable que, con el menú plegado,
                                     * quedaba fuera de la vista: parecía que el botón
                                     * no hacía nada. Así se comporta igual plegado y
                                     * desplegado.
                                     */
                                    onClick={() => setChooserDone(false)}
                                    title="Cambiar de área"
                                    onMouseEnter={(e) => {
                                        if (expanded) return;
                                        const r =
                                            e.currentTarget.getBoundingClientRect();
                                        setRailTip({
                                            label: `Área: ${area.label}`,
                                            top: r.top + r.height / 2,
                                            left: r.right + 12,
                                            color: "#2563eb",
                                        });
                                    }}
                                    onMouseLeave={() => setRailTip(null)}
                                >
                                    <span className="area-ico">
                                        <area.icon />
                                    </span>
                                    {expanded && (
                                        <span className="area-name">
                                            {area.label}
                                        </span>
                                    )}
                                    {/* Sin flecha: ya no despliega un menú, abre la
                                        pantalla de elección de área. */}
                                </button>
                            </div>
                        )}
                        <div className="rail-nav">
                            {navGroups.map((g, gi) => (
                                <div key={gi} className="rail-group">
                                    {expanded && g.title && (
                                        <div className="rail-group-t">
                                            {g.title}
                                        </div>
                                    )}
                                    {g.items.map((n) => (
                                        <button
                                            key={n.key}
                                            className={`rail-btn ${view === n.key ? "active" : ""}`}
                                            style={{ "--bc": n.color }}
                                            onClick={() => setView(n.key)}
                                            onMouseEnter={(e) => {
                                                if (expanded) return;
                                                const r =
                                                    e.currentTarget.getBoundingClientRect();
                                                setRailTip({
                                                    label: n.label,
                                                    top: r.top + r.height / 2,
                                                    left: r.right + 12,
                                                    color: n.color,
                                                });
                                            }}
                                            onMouseLeave={() =>
                                                setRailTip(null)
                                            }
                                        >
                                            <n.icon />
                                            {expanded && (
                                                <span className="rail-text">
                                                    {n.label}
                                                </span>
                                            )}
                                            {n.key === "inbox" &&
                                                unread > 0 && (
                                                    <span className="dot-badge">
                                                        {unread > 99
                                                            ? "99+"
                                                            : unread}
                                                    </span>
                                                )}
                                        </button>
                                    ))}
                                </div>
                            ))}
                        </div>
                        <div className="spacer" />

                        {/* Campana de notificaciones (menciones, y a futuro asignaciones/SLA) */}
                        {can("helpdesk.access") && (
                            <NotificationBell
                                expanded={expanded}
                                onOpenTicket={(id) => {
                                    setTicketsTab("tickets");
                                    setTicketAbierto(id);
                                    setView("tickets");
                                }}
                            />
                        )}

                        {/* --- Cuenta: al pie del sidebar (ya no hay barra superior) --- */}
                        <div className="rail-user">
                            <button
                                className="ru-card"
                                onClick={() => setView("account")}
                                title="Mi cuenta"
                            >
                                <span className="ru-av">
                                    {initialsOf(auth.user)}
                                </span>
                                {expanded && (
                                    <span className="ru-tx">
                                        <b>
                                            {auth.user?.name ||
                                                auth.user?.email}
                                        </b>
                                        <small>{auth.user?.email}</small>
                                    </span>
                                )}
                            </button>
                            {expanded && (
                                <button className="ru-out" onClick={logout}>
                                    <Icon.logout /> Cerrar sesión
                                </button>
                            )}
                        </div>

                        <div className="rail-actions">
                            {/* Ajustes de la cuenta: hace lo mismo que pulsar sobre el usuario */}
                            <button
                                className="ra-btn"
                                onClick={() => setView("account")}
                                title="Mi cuenta"
                            >
                                <Icon.settings />
                            </button>
                            {/* Notificaciones web: accesible desde CUALQUIER sección (avisos por dispositivo). */}
                            <button
                                className={`ra-btn ${view === "webpush" ? "on" : ""}`}
                                onClick={() => setView("webpush")}
                                title="Notificaciones web"
                            >
                                <Icon.bell />
                            </button>
                            <button
                                className="ra-btn"
                                onClick={toggleTheme}
                                title={
                                    theme === "dark"
                                        ? "Modo claro"
                                        : "Modo oscuro"
                                }
                            >
                                {theme === "dark" ? (
                                    <Icon.sun />
                                ) : (
                                    <Icon.moon />
                                )}
                            </button>
                            {!expanded && (
                                <button
                                    className="ra-btn"
                                    onClick={logout}
                                    title="Cerrar sesión"
                                >
                                    <Icon.logout />
                                </button>
                            )}
                            {expanded && (
                                <span className="ver">v{APP_VERSION}</span>
                            )}
                        </div>

                        <button className="rail-collapse" onClick={toggleRail}>
                            <Icon.chevron
                                style={{
                                    transform: expanded
                                        ? "rotate(90deg)"
                                        : "rotate(-90deg)",
                                }}
                            />
                            {expanded && <span>Contraer menú</span>}
                        </button>
                    </nav>
                    {!expanded && railTip && (
                        <div
                            className="rail-tip"
                            style={{
                                top: railTip.top,
                                left: railTip.left,
                                "--bc": railTip.color,
                            }}
                        >
                            {railTip.label}
                        </div>
                    )}

                    <div className="main">
                        {view === "dashboard" && (
                            <Dashboard
                                user={auth.user}
                                onOpen={openConversation}
                            />
                        )}
                        {view === "support" && (
                            <SupportCenter
                                /*
                                 * El segundo dato puede ser una PESTAÑA ('cron') o el
                                 * ID de un ticket concreto: los «tickets recientes»
                                 * mandan el id para que se abra ese, no la lista.
                                 * Antes llegaba como pestaña, no casaba con ninguna,
                                 * y por eso se quedaba en el listado sin más.
                                 */
                                onGo={(v, extra) => {
                                    const id = Number(extra);
                                    const esTicket = Number.isInteger(id) && id > 0;
                                    setTicketsTab(esTicket ? "tickets" : extra || "tickets");
                                    setTicketAbierto(esTicket ? id : null);
                                    setView(v);
                                }}
                                user={auth.user}
                            />
                        )}
                        {view === "ticket_new" && (
                            <NewTicket
                                user={auth.user}
                                onCreated={() => setView("tickets")}
                                onOpenTicket={(id) => {
                                    setTicketsTab("tickets");
                                    setTicketAbierto(id);
                                    setView("tickets");
                                }}
                                onCancel={() => setView("support")}
                            />
                        )}
                        {view === "shifts" && <Shifts />}
                        {view === "reports" && <Reports onGo={setView} />}
                        {view === "activity" && <Activity />}
                        {view === "support_cfg" && <SupportSettings user={auth.user} />}
                        {view === "wa_config" && <Settings />}
                        {view === "campaign_email_cfg" && <CampaignEmailSettings />}
                        {view === "help" && <Manuals />}
                        {view === "tickets" && (
                            <Tickets user={auth.user} onGo={setView} initialTab={ticketsTab} initialTicket={ticketAbierto} initialOrg={orgFiltro} />
                        )}
                        {view === "kanban" && (
                            <Kanban onOpen={openConversation} />
                        )}
                        {/* Los contactos se separan por área: Campañas ve los de
                            WhatsApp; Helpdesk, los que tienen tickets. */}
                        {view === "contacts" && <Contacts area={area.key} />}
                        {view === "automations" && <Automations />}
                        {view === "bot_responses" && (
                            <BotResponses onOpen={openConversation} />
                        )}
                        {view === "forms" && <Forms />}
                        {view === "inbox" && (
                            <Inbox
                                onUnread={setUnread}
                                initialContactId={openTarget}
                                onOpened={() => setOpenTarget(null)}
                            />
                        )}
                        {view === "templates" && <Templates />}
                        {view === "campaign_send" && (
                            <SendCampaign
                                onDone={() => setView("campaign_dash")}
                            />
                        )}
                        {view === "campaign_dash" && (
                            <CampaignDashboard
                                onNew={() => setView("campaign_send")}
                                user={auth.user}
                            />
                        )}
                        {view === "phonebook" && <Phonebook />}
                        {view === "organizations" && (
                            <Organizations onVerTickets={(org) => { setOrgFiltro(org); setTicketsTab("tickets"); setView("tickets"); }} />
                        )}
                        {view === "webpush" && <WebNotifications />}
                        {view === "analytics" && can("analytics.view") && (
                            <Analytics />
                        )}
                        {view === "users" && can("users.manage") && <Users />}
                        {view === "account" && (
                            <Account
                                user={auth.user}
                                onAccountChange={(u) =>
                                    setAuth((a) => ({
                                        ...a,
                                        user: { ...a.user, ...u },
                                    }))
                                }
                            />
                        )}
                    </div>
                </div>
                {confirmState && (
                    <ConfirmDialog
                        opts={confirmState.opts}
                        onClose={closeConfirm}
                    />
                )}
            </ConfirmCtx.Provider>
            <ToastHost toasts={toasts} onClose={cerrarToast} />
        </ToastCtx.Provider>
    );
}
