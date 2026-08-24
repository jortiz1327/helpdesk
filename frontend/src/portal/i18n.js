/* ---------------------------------------------------------------------------
 * i18n LIGERO del PORTAL PÚBLICO — sin librerías.
 *
 * Tres idiomas: español (por defecto), inglés y portugués. Cada clave es un
 * identificador estable; el valor es el texto en ese idioma. Algunas cadenas
 * llevan <b>…</b> (énfasis) o variables {x}: el <b> lo pinta el componente con
 * dangerouslySetInnerHTML (texto propio y estático, seguro), y las {x} las
 * sustituye `t(key, vars)`.
 *
 * SOLO el portal del cliente usa esto; la app de agentes NO se toca.
 * ------------------------------------------------------------------------- */
import { createContext, useContext, useState, useCallback, createElement } from 'react'

export const dict = {
  es: {
    /* ---- Barra superior ---- */
    top_home_title: 'Volver al inicio',
    top_home_aria: 'Ir al inicio',
    agent_access: 'Acceso agentes',
    lang_label: 'Idioma',

    /* ---- Home ---- */
    status_open: 'Estamos atendiendo ahora',
    status_closed: 'Ahora fuera de horario · te leemos pronto',
    hero_greeting: 'Buenas',
    hero_help: '¿En qué podemos ayudarte?',
    hero_sub_1: 'Busca tu duda o abre una incidencia.',
    hero_sub_2: 'Un técnico te responde por correo, de lunes a viernes de 7:00 a 21:00.',
    search_placeholder: 'Escribe tu duda… ej: las etiquetas no cargan',
    clear: 'Borrar',
    try_with: 'Prueba con',
    sug_1: 'las etiquetas no cargan',
    sug_2: 'cambiar el menú',
    sug_3: 'repetidor apagado',
    sug_4: 'etiqueta rota',
    faq_title: 'Dudas frecuentes',
    result_one: 'resultado',
    result_many: 'resultados',
    vote_thanks: '¡Gracias por tu voto!',
    vote_q: '¿Te ha servido?',
    vote_yes_aria: 'Sí, me ha servido',
    vote_no_aria: 'No me ha servido',
    faq_cta: 'No me sirve, abrir incidencia',
    empty_search: 'No encontramos nada con «{q}».',
    empty_no_faq: 'Aún no hay preguntas frecuentes.',
    empty_search_sub: 'Prueba con otras palabras, o cuéntanoslo y lo resolvemos contigo.',
    empty_no_faq_sub: 'Cuéntanos tu caso y lo resolvemos contigo.',
    create_ticket: 'Crear una incidencia',
    not_here: '¿No lo encuentras aquí?',
    create_ticket_desc: 'Cuéntanos el problema y te asignamos un técnico.',
    my_tickets: 'Ver mis incidencias',
    my_tickets_desc: 'Consulta el estado y responde a las tuyas.',
    est_link: '¿Ya tienes tu número? <b>Consulta el estado</b> sin correo',
    help_center: 'Centro de atención',

    /* ---- Acceso ---- */
    change_email: 'Cambiar de correo',
    back_home: 'Volver al inicio',
    session_expired: 'Tu sesión ha caducado por seguridad. Confirma tu correo otra vez.',
    acc_email_desc: 'Escribe tu <b>correo</b> y te enviamos un código para confirmar que eres tú.',
    your_email: 'Tu correo',
    email_placeholder: 'nombre@tuempresa.com',
    sending: 'Enviando…',
    wait_seconds: 'Espera {n}s',
    send_code: 'Enviarme el código',
    have_code: 'Ya tengo un código →',
    acc_trust_email: 'No hace falta registrarse. El código solo confirma que <b>este correo es tuyo</b> — así nadie más puede ver tus incidencias.',
    check_email: 'Revisa tu correo',
    code_desc: 'Escribe el código de 6 dígitos que enviamos a <b>{mail}</b>.',
    resend_q: '¿No te llega o ha caducado?',
    resend_wait: 'Puedes pedir otro en {n}s',
    resend_new: 'Enviar uno nuevo',
    code_trust: 'Mira también en <b>spam</b> o «no deseado». El código caduca a los <b>10 minutos</b>.',
    err_send_code: 'No se pudo enviar el código',
    err_bad_code: 'Código incorrecto',

    /* ---- Crear ---- */
    err_create: 'No se pudo crear la incidencia',
    created_title: '¡Incidencia creada!',
    created_desc: 'Guarda este número: con él y tu correo puedes seguir su estado cuando quieras.',
    your_ticket_number: 'Tu número de incidencia',
    copied: 'Copiado',
    copy: 'Copiar',
    created_mail_note: 'Te avisaremos por correo en cuanto un técnico responda.',
    view_ticket: 'Ver la incidencia',
    create_form_title: 'Cuéntanos qué pasa',
    create_form_desc: 'Sin registros ni contraseñas. Rellénalo y verás tu incidencia al instante.',
    dup_open_title: 'Ya tienes incidencias abiertas',
    dup_open_sub: 'Si es lo mismo, puedes responder ahí en vez de abrir otra.',
    email_hint: 'Aquí te avisamos cuando un técnico responda.',
    subject: 'Asunto',
    subject_placeholder: 'Ej: Las etiquetas de la tienda no cargan',
    category: 'Categoría',
    description: 'Descripción',
    desc_placeholder: '¿Qué ocurre? ¿Desde cuándo? ¿Qué has probado ya?',
    too_short: 'Cuéntanos un poco más para poder ayudarte.',
    attach_label: 'Adjuntar',
    attach_optional: '(opcional, ayuda mucho una captura)',
    send_ticket: 'Enviar incidencia',

    /* ---- Adjuntar ---- */
    attach_short: 'Adjuntar',
    attach_a_file: 'Adjunta un archivo',
    attach_hint: 'o arrástralo aquí · imágenes, PDF o documentos (máx. 10 MB)',
    remove: 'Quitar',

    /* ---- Correo (iframe) ---- */
    email_frame_title: 'Mensaje',
    see_less: 'Ver menos',
    see_full_email: 'Ver el correo completo',

    /* ---- Estado ---- */
    updated: 'Actualizada {time}',
    resolved_sub: 'Se resolvió {time}. Si el problema vuelve, respóndenos y la reabrimos.',
    created_label: 'Creada',
    resolved_label: 'Resuelta',
    last_update: 'Última novedad',
    status_only_note: 'Aquí solo ves el estado. Para leer la conversación o responder:',
    enter_with_email: 'Entrar con mi correo',
    check_another: 'Consultar otro número',
    err_not_found: 'No encontramos esa incidencia',
    status_form_title: 'Ver el estado de tu incidencia',
    status_form_desc: 'Escribe tu número y te decimos cómo va. Sin contraseñas ni esperas.',
    ticket_number_label: 'Número de incidencia',
    checking: 'Consultando…',
    view_status: 'Ver estado',
    status_trust: 'Aquí solo se ve el <b>estado</b>. Para leer la conversación o responder, se entra con el correo.',

    /* ---- Fases del ticket ---- */
    phase_received: 'Recibida',
    phase_received_sub: 'La hemos recibido y la revisaremos en breve.',
    phase_progress: 'En proceso',
    phase_progress_sub: 'Nuestro equipo está trabajando en ella.',
    phase_resolved: 'Resuelta',
    phase_resolved_sub: 'Se ha dado por resuelta. Si vuelve, respóndenos.',

    /* ---- Tarjeta de ticket ---- */
    last_support: 'Soporte te respondió',
    last_waiting: 'Enviado · esperando respuesta',
    new_reply: 'Respuesta nueva',

    /* ---- Mis incidencias ---- */
    home: 'Inicio',
    my_tickets_title: 'Tus incidencias',
    none_yet: 'Aún no has abierto ninguna',
    mis_summary: '{total} en total · {open} {openWord}',
    open_one: 'abierta',
    open_many: 'abiertas',
    create_ticket_short: 'Crear incidencia',
    filter_all: 'Todas',
    filter_open: 'Abiertas',
    filter_resolved: 'Resueltas',
    loading: 'Cargando…',
    mis_empty_title: 'Aún no tienes incidencias',
    mis_empty_sub: 'Cuando abras una, aquí verás su estado y podrás responder.',
    group_open: 'Abiertas',
    group_open_sub: 'en seguimiento',
    group_resolved: 'Resueltas',
    group_resolved_sub: 'cerradas',
    no_open: 'No tienes incidencias abiertas. 🎉',
    no_resolved: 'Aún no tienes incidencias resueltas.',

    /* ---- Detalle ---- */
    my_tickets_back: 'Mis incidencias',
    opened_rel: '{code} · abierta {time}',
    marking: 'Marcando…',
    resolved_for_me: 'Ya está resuelto para mí',
    reply_reopen_note: '¿El problema ha vuelto? Respóndenos y reabrimos la incidencia.',
    reply_again: 'Escribir de nuevo',
    reply_to_support: 'Responder a soporte',
    reply_ph_reopen: 'Cuéntanos si el problema ha vuelto y lo retomamos…',
    reply_ph: 'Escribe aquí tu mensaje para el equipo de soporte…',
    reply_btn: 'Responder',
    me_avatar: 'Tú',
    me_name: 'Tú',
    support_name: 'Soporte AEME',

    /* ---- CSAT ---- */
    csat_thanks: '¡Gracias por tu valoración!',
    csat_q: '¿Cómo valoras la atención recibida?',
    csat_sub: 'Un clic y listo. Nos ayuda a mejorar.',
    csat_star_aria: '{n} de 5',
    csat_comment_ph: '¿Quieres contarnos algo más? (opcional)',
    saved: 'Guardado',
    saving: 'Guardando…',
    csat_send: 'Enviar comentario',

    /* ---- Pie ---- */
    foot_blurb: 'Soluciones tecnológicas para el retail: etiquetas electrónicas, menús digitales y consultoría para el punto de venta.',
    visit_web: 'Visita nuestra web',
    sectors: 'Sectores',
    sector_hotels: 'Hoteles',
    sector_pharmacies: 'Farmacias',
    sector_supermarkets: 'Supermercados',
    sector_butchers: 'Carnicerías',
    sector_gas: 'Gasolineras',
    foot_hours: 'Soporte · Lun–Vie 07:00–21:00',

    /* ---- Tiempo relativo ---- */
    rel_now: 'ahora mismo',
    rel_min: 'hace {n} min',
    rel_hour: 'hace {n} h',
    rel_yesterday: 'ayer',
    rel_days: 'hace {n} días',
  },

  en: {
    top_home_title: 'Back to home',
    top_home_aria: 'Go to home',
    agent_access: 'Agent login',
    lang_label: 'Language',

    status_open: 'We are online now',
    status_closed: 'Currently offline · we will reply soon',
    hero_greeting: 'Hi',
    hero_help: 'How can we help you?',
    hero_sub_1: 'Search your question or open a ticket.',
    hero_sub_2: 'A technician replies by email, Monday to Friday from 7:00 to 21:00.',
    search_placeholder: 'Type your question… e.g. labels not loading',
    clear: 'Clear',
    try_with: 'Try',
    sug_1: 'labels not loading',
    sug_2: 'change the menu',
    sug_3: 'repeater is off',
    sug_4: 'broken label',
    faq_title: 'FAQ',
    result_one: 'result',
    result_many: 'results',
    vote_thanks: 'Thanks for your feedback!',
    vote_q: 'Was this helpful?',
    vote_yes_aria: 'Yes, it helped',
    vote_no_aria: 'No, it did not help',
    faq_cta: 'This did not help, open a ticket',
    empty_search: 'No results for “{q}”.',
    empty_no_faq: 'There are no FAQs yet.',
    empty_search_sub: 'Try other words, or tell us and we will sort it out together.',
    empty_no_faq_sub: 'Tell us about your case and we will sort it out together.',
    create_ticket: 'Open a ticket',
    not_here: 'Can’t find it here?',
    create_ticket_desc: 'Tell us the problem and we will assign a technician.',
    my_tickets: 'View my tickets',
    my_tickets_desc: 'Check the status and reply to yours.',
    est_link: 'Already have your number? <b>Check the status</b> without email',
    help_center: 'Help center',

    change_email: 'Change email',
    back_home: 'Back to home',
    session_expired: 'Your session has expired for security. Please confirm your email again.',
    acc_email_desc: 'Enter your <b>email</b> and we will send you a code to confirm it is you.',
    your_email: 'Your email',
    email_placeholder: 'name@yourcompany.com',
    sending: 'Sending…',
    wait_seconds: 'Wait {n}s',
    send_code: 'Send me the code',
    have_code: 'I already have a code →',
    acc_trust_email: 'No sign-up needed. The code only confirms that <b>this email is yours</b> — so no one else can see your tickets.',
    check_email: 'Check your email',
    code_desc: 'Enter the 6-digit code we sent to <b>{mail}</b>.',
    resend_q: 'Didn’t get it or it expired?',
    resend_wait: 'You can request another in {n}s',
    resend_new: 'Send a new one',
    code_trust: 'Check your <b>spam</b> or junk folder too. The code expires after <b>10 minutes</b>.',
    err_send_code: 'The code could not be sent',
    err_bad_code: 'Incorrect code',

    err_create: 'The ticket could not be created',
    created_title: 'Ticket created!',
    created_desc: 'Save this number: with it and your email you can track its status anytime.',
    your_ticket_number: 'Your ticket number',
    copied: 'Copied',
    copy: 'Copy',
    created_mail_note: 'We will email you as soon as a technician replies.',
    view_ticket: 'View the ticket',
    create_form_title: 'Tell us what’s going on',
    create_form_desc: 'No sign-ups or passwords. Fill it in and you’ll see your ticket right away.',
    dup_open_title: 'You already have open tickets',
    dup_open_sub: 'If it’s the same issue, reply there instead of opening a new one.',
    email_hint: 'This is where we notify you when a technician replies.',
    subject: 'Subject',
    subject_placeholder: 'E.g. The store labels are not loading',
    category: 'Category',
    description: 'Description',
    desc_placeholder: 'What is happening? Since when? What have you tried?',
    too_short: 'Tell us a bit more so we can help you.',
    attach_label: 'Attach',
    attach_optional: '(optional, a screenshot helps a lot)',
    send_ticket: 'Send ticket',

    attach_short: 'Attach',
    attach_a_file: 'Attach a file',
    attach_hint: 'or drag it here · images, PDF or documents (max. 10 MB)',
    remove: 'Remove',

    email_frame_title: 'Message',
    see_less: 'See less',
    see_full_email: 'See the full email',

    updated: 'Updated {time}',
    resolved_sub: 'Resolved {time}. If the problem comes back, reply and we will reopen it.',
    created_label: 'Created',
    resolved_label: 'Resolved',
    last_update: 'Last update',
    status_only_note: 'Here you only see the status. To read the conversation or reply:',
    enter_with_email: 'Sign in with my email',
    check_another: 'Check another number',
    err_not_found: 'We could not find that ticket',
    status_form_title: 'Check your ticket status',
    status_form_desc: 'Enter your number and we’ll tell you how it’s going. No passwords, no waiting.',
    ticket_number_label: 'Ticket number',
    checking: 'Checking…',
    view_status: 'Check status',
    status_trust: 'Here you only see the <b>status</b>. To read the conversation or reply, sign in with your email.',

    phase_received: 'Received',
    phase_received_sub: 'We have received it and will review it shortly.',
    phase_progress: 'In progress',
    phase_progress_sub: 'Our team is working on it.',
    phase_resolved: 'Resolved',
    phase_resolved_sub: 'It has been marked as resolved. If it comes back, reply to us.',

    last_support: 'Support replied to you',
    last_waiting: 'Sent · awaiting reply',
    new_reply: 'New reply',

    home: 'Home',
    my_tickets_title: 'Your tickets',
    none_yet: 'You haven’t opened any yet',
    mis_summary: '{total} in total · {open} {openWord}',
    open_one: 'open',
    open_many: 'open',
    create_ticket_short: 'New ticket',
    filter_all: 'All',
    filter_open: 'Open',
    filter_resolved: 'Resolved',
    loading: 'Loading…',
    mis_empty_title: 'You don’t have any tickets yet',
    mis_empty_sub: 'When you open one, you’ll see its status here and be able to reply.',
    group_open: 'Open',
    group_open_sub: 'being handled',
    group_resolved: 'Resolved',
    group_resolved_sub: 'closed',
    no_open: 'You have no open tickets. 🎉',
    no_resolved: 'You don’t have any resolved tickets yet.',

    my_tickets_back: 'My tickets',
    opened_rel: '{code} · opened {time}',
    marking: 'Marking…',
    resolved_for_me: 'This is resolved for me',
    reply_reopen_note: 'Has the problem come back? Reply and we’ll reopen the ticket.',
    reply_again: 'Write again',
    reply_to_support: 'Reply to support',
    reply_ph_reopen: 'Tell us if the problem has come back and we’ll pick it up again…',
    reply_ph: 'Write your message for the support team here…',
    reply_btn: 'Reply',
    me_avatar: 'You',
    me_name: 'You',
    support_name: 'AEME Support',

    csat_thanks: 'Thanks for your rating!',
    csat_q: 'How would you rate the support you received?',
    csat_sub: 'One click and done. It helps us improve.',
    csat_star_aria: '{n} of 5',
    csat_comment_ph: 'Want to tell us more? (optional)',
    saved: 'Saved',
    saving: 'Saving…',
    csat_send: 'Send comment',

    foot_blurb: 'Technology solutions for retail: electronic shelf labels, digital menus and consulting for the point of sale.',
    visit_web: 'Visit our website',
    sectors: 'Sectors',
    sector_hotels: 'Hotels',
    sector_pharmacies: 'Pharmacies',
    sector_supermarkets: 'Supermarkets',
    sector_butchers: 'Butchers',
    sector_gas: 'Gas stations',
    foot_hours: 'Support · Mon–Fri 07:00–21:00',

    rel_now: 'just now',
    rel_min: '{n} min ago',
    rel_hour: '{n} h ago',
    rel_yesterday: 'yesterday',
    rel_days: '{n} days ago',
  },

  pt: {
    top_home_title: 'Voltar ao início',
    top_home_aria: 'Ir para o início',
    agent_access: 'Acesso de agentes',
    lang_label: 'Idioma',

    status_open: 'Estamos a atender agora',
    status_closed: 'Agora fora de horário · respondemos em breve',
    hero_greeting: 'Olá',
    hero_help: 'Como podemos ajudar?',
    hero_sub_1: 'Procure a sua dúvida ou abra um chamado.',
    hero_sub_2: 'Um técnico responde por email, de segunda a sexta das 7:00 às 21:00.',
    search_placeholder: 'Escreva a sua dúvida… ex: as etiquetas não carregam',
    clear: 'Limpar',
    try_with: 'Experimente',
    sug_1: 'as etiquetas não carregam',
    sug_2: 'mudar o menu',
    sug_3: 'repetidor desligado',
    sug_4: 'etiqueta partida',
    faq_title: 'Perguntas frequentes',
    result_one: 'resultado',
    result_many: 'resultados',
    vote_thanks: 'Obrigado pelo seu voto!',
    vote_q: 'Foi útil?',
    vote_yes_aria: 'Sim, ajudou',
    vote_no_aria: 'Não ajudou',
    faq_cta: 'Não me serve, abrir chamado',
    empty_search: 'Não encontrámos nada com «{q}».',
    empty_no_faq: 'Ainda não há perguntas frequentes.',
    empty_search_sub: 'Tente outras palavras, ou conte-nos e resolvemos juntos.',
    empty_no_faq_sub: 'Conte-nos o seu caso e resolvemos juntos.',
    create_ticket: 'Abrir um chamado',
    not_here: 'Não encontra aqui?',
    create_ticket_desc: 'Conte-nos o problema e atribuímos um técnico.',
    my_tickets: 'Ver os meus chamados',
    my_tickets_desc: 'Consulte o estado e responda aos seus.',
    est_link: 'Já tem o seu número? <b>Consulte o estado</b> sem email',
    help_center: 'Centro de atendimento',

    change_email: 'Mudar de email',
    back_home: 'Voltar ao início',
    session_expired: 'A sua sessão expirou por segurança. Confirme o seu email novamente.',
    acc_email_desc: 'Escreva o seu <b>email</b> e enviamos um código para confirmar que é você.',
    your_email: 'O seu email',
    email_placeholder: 'nome@suaempresa.com',
    sending: 'A enviar…',
    wait_seconds: 'Aguarde {n}s',
    send_code: 'Enviar-me o código',
    have_code: 'Já tenho um código →',
    acc_trust_email: 'Não é preciso registar-se. O código apenas confirma que <b>este email é seu</b> — assim mais ninguém pode ver os seus chamados.',
    check_email: 'Verifique o seu email',
    code_desc: 'Escreva o código de 6 dígitos que enviámos para <b>{mail}</b>.',
    resend_q: 'Não recebeu ou expirou?',
    resend_wait: 'Pode pedir outro em {n}s',
    resend_new: 'Enviar um novo',
    code_trust: 'Verifique também o <b>spam</b> ou lixo. O código expira ao fim de <b>10 minutos</b>.',
    err_send_code: 'Não foi possível enviar o código',
    err_bad_code: 'Código incorreto',

    err_create: 'Não foi possível criar o chamado',
    created_title: 'Chamado criado!',
    created_desc: 'Guarde este número: com ele e o seu email pode acompanhar o estado quando quiser.',
    your_ticket_number: 'O seu número de chamado',
    copied: 'Copiado',
    copy: 'Copiar',
    created_mail_note: 'Avisamos por email assim que um técnico responder.',
    view_ticket: 'Ver o chamado',
    create_form_title: 'Conte-nos o que se passa',
    create_form_desc: 'Sem registos nem palavras-passe. Preencha e verá o seu chamado de imediato.',
    dup_open_title: 'Já tem chamados abertos',
    dup_open_sub: 'Se for o mesmo, responda aí em vez de abrir outro.',
    email_hint: 'É aqui que o avisamos quando um técnico responder.',
    subject: 'Assunto',
    subject_placeholder: 'Ex: As etiquetas da loja não carregam',
    category: 'Categoria',
    description: 'Descrição',
    desc_placeholder: 'O que acontece? Desde quando? O que já experimentou?',
    too_short: 'Conte-nos um pouco mais para o podermos ajudar.',
    attach_label: 'Anexar',
    attach_optional: '(opcional, uma captura de ecrã ajuda muito)',
    send_ticket: 'Enviar chamado',

    attach_short: 'Anexar',
    attach_a_file: 'Anexe um ficheiro',
    attach_hint: 'ou arraste-o para aqui · imagens, PDF ou documentos (máx. 10 MB)',
    remove: 'Remover',

    email_frame_title: 'Mensagem',
    see_less: 'Ver menos',
    see_full_email: 'Ver o email completo',

    updated: 'Atualizado {time}',
    resolved_sub: 'Resolvido {time}. Se o problema voltar, responda e reabrimos.',
    created_label: 'Criado',
    resolved_label: 'Resolvido',
    last_update: 'Última novidade',
    status_only_note: 'Aqui só vê o estado. Para ler a conversa ou responder:',
    enter_with_email: 'Entrar com o meu email',
    check_another: 'Consultar outro número',
    err_not_found: 'Não encontrámos esse chamado',
    status_form_title: 'Ver o estado do seu chamado',
    status_form_desc: 'Escreva o seu número e dizemos-lhe como está. Sem palavras-passe nem esperas.',
    ticket_number_label: 'Número de chamado',
    checking: 'A consultar…',
    view_status: 'Ver estado',
    status_trust: 'Aqui só se vê o <b>estado</b>. Para ler a conversa ou responder, entra-se com o email.',

    phase_received: 'Recebido',
    phase_received_sub: 'Recebemo-lo e vamos revê-lo em breve.',
    phase_progress: 'Em curso',
    phase_progress_sub: 'A nossa equipa está a trabalhar nele.',
    phase_resolved: 'Resolvido',
    phase_resolved_sub: 'Foi dado como resolvido. Se voltar, responda-nos.',

    last_support: 'O suporte respondeu-lhe',
    last_waiting: 'Enviado · a aguardar resposta',
    new_reply: 'Nova resposta',

    home: 'Início',
    my_tickets_title: 'Os seus chamados',
    none_yet: 'Ainda não abriu nenhum',
    mis_summary: '{total} no total · {open} {openWord}',
    open_one: 'aberto',
    open_many: 'abertos',
    create_ticket_short: 'Criar chamado',
    filter_all: 'Todos',
    filter_open: 'Abertos',
    filter_resolved: 'Resolvidos',
    loading: 'A carregar…',
    mis_empty_title: 'Ainda não tem chamados',
    mis_empty_sub: 'Quando abrir um, verá aqui o seu estado e poderá responder.',
    group_open: 'Abertos',
    group_open_sub: 'em acompanhamento',
    group_resolved: 'Resolvidos',
    group_resolved_sub: 'fechados',
    no_open: 'Não tem chamados abertos. 🎉',
    no_resolved: 'Ainda não tem chamados resolvidos.',

    my_tickets_back: 'Os meus chamados',
    opened_rel: '{code} · aberto {time}',
    marking: 'A marcar…',
    resolved_for_me: 'Já está resolvido para mim',
    reply_reopen_note: 'O problema voltou? Responda e reabrimos o chamado.',
    reply_again: 'Escrever de novo',
    reply_to_support: 'Responder ao suporte',
    reply_ph_reopen: 'Conte-nos se o problema voltou e retomamos…',
    reply_ph: 'Escreva aqui a sua mensagem para a equipa de suporte…',
    reply_btn: 'Responder',
    me_avatar: 'Eu',
    me_name: 'Eu',
    support_name: 'Suporte AEME',

    csat_thanks: 'Obrigado pela sua avaliação!',
    csat_q: 'Como avalia o atendimento recebido?',
    csat_sub: 'Um clique e pronto. Ajuda-nos a melhorar.',
    csat_star_aria: '{n} de 5',
    csat_comment_ph: 'Quer contar-nos mais alguma coisa? (opcional)',
    saved: 'Guardado',
    saving: 'A guardar…',
    csat_send: 'Enviar comentário',

    foot_blurb: 'Soluções tecnológicas para o retalho: etiquetas eletrónicas, menus digitais e consultoria para o ponto de venda.',
    visit_web: 'Visite o nosso site',
    sectors: 'Setores',
    sector_hotels: 'Hotéis',
    sector_pharmacies: 'Farmácias',
    sector_supermarkets: 'Supermercados',
    sector_butchers: 'Talhos',
    sector_gas: 'Postos de combustível',
    foot_hours: 'Suporte · Seg–Sex 07:00–21:00',

    rel_now: 'agora mesmo',
    rel_min: 'há {n} min',
    rel_hour: 'há {n} h',
    rel_yesterday: 'ontem',
    rel_days: 'há {n} dias',
  },
}

/* Siglas para el selector de la cabecera. */
export const LANGS = [['es', 'ES'], ['en', 'EN'], ['pt', 'PT']]

/* Locale para fechas nativas (toLocaleDateString/String) por idioma. */
export const LOCALES = { es: 'es-ES', en: 'en-GB', pt: 'pt-PT' }

/* Idioma inicial: lo guardado → el del navegador → 'es'. */
function detect() {
  try { const s = localStorage.getItem('portal_lang'); if (s && dict[s]) return s } catch { /* sin storage */ }
  try {
    const n = (navigator.language || '').slice(0, 2).toLowerCase()
    if (n === 'en') return 'en'
    if (n === 'pt') return 'pt'
    if (n === 'es') return 'es'
  } catch { /* sin navigator */ }
  return 'es'
}

/* Interpolación simple de {variables}. */
function interpolate(s, vars) {
  if (!vars) return s
  return s.replace(/\{(\w+)\}/g, (m, k) => (vars[k] != null ? String(vars[k]) : m))
}

const LangCtx = createContext(null)

export function LangProvider({ children }) {
  const [lang, setLangState] = useState(detect)
  const setLang = useCallback((l) => {
    if (!dict[l]) return
    try { localStorage.setItem('portal_lang', l) } catch { /* sin storage */ }
    setLangState(l)
  }, [])
  const t = useCallback((key, vars) => {
    const table = dict[lang] || dict.es
    const raw = key in table ? table[key] : (key in dict.es ? dict.es[key] : key)
    return interpolate(raw, vars)
  }, [lang])
  return createElement(LangCtx.Provider, { value: { lang, setLang, t } }, children)
}

/* Hook: { lang, setLang, t }. Fuera del provider cae a español (nunca peta). */
export function useLang() {
  const ctx = useContext(LangCtx)
  if (ctx) return ctx
  return {
    lang: 'es',
    setLang: () => {},
    t: (key, vars) => interpolate(key in dict.es ? dict.es[key] : key, vars),
  }
}
