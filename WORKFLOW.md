# Student Support Agent - Workflow Documentation

## Overview

Este plugin de Moodle implementa un **agente de soporte educativo** que ayuda a los estudiantes con sus preguntas académicas usando un enfoque pedagógico guiado. El agente **nunca da respuestas directas**, sino que guía al estudiante hacia el aprendizaje mediante preguntas, ejemplos y explicaciones.

**PRINCIPIO FUNDAMENTAL:** El agente adapta su respuesta según la **fase cognitiva** del estudiante:
- **NO_MENTAL_MODEL**: Explicación directa sin preguntas ni analogías
- **PARTIAL_MENTAL_MODEL**: Explicación con preguntas opcionales
- **FUNCTIONAL_MENTAL_MODEL**: Método socrático completo con exploración

---

## Framework GAME

El agente implementa el **Framework GAME** (Goal, Actions, Memory, Environment):

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           GAME FRAMEWORK                                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  G - GOAL (Objetivo)                                                  │   │
│  │  Define el objetivo pedagógico del agente:                           │   │
│  │  • Guiar sin dar respuestas directas                                 │   │
│  │  • Mantener integridad académica                                     │   │
│  │  • Respetar privacidad del estudiante                                │   │
│  │  • Mantener tono profesional y empático                              │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  A - ACTIONS (Acciones)                                               │   │
│  │  Intervenciones pedagógicas disponibles:                             │   │
│  │  • direct_explanation - Explicación directa (NO_MODEL phase)         │   │
│  │  • explain_concept - Explicar conceptos (con preguntas opcionales)   │   │
│  │  • ask_guiding_question - Preguntas socráticas (FUNCTIONAL phase)    │   │
│  │  • give_example - Dar ejemplos ilustrativos                          │   │
│  │  • rephrase_instruction - Reformular explicaciones                   │   │
│  │  • give_practice_problem - Problemas de práctica                     │   │
│  │  • micro_scaffold - Preguntas simples para estudiantes atascados     │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  M - MEMORY (Memoria)                                                 │   │
│  │  Estado y contexto de la conversación:                               │   │
│  │  • Cognitive Phase - Fase cognitiva (NO/PARTIAL/FUNCTIONAL)          │   │
│  │  • Cognitive State - Estado emocional/situacional                    │   │
│  │  • Signals - Señales detectadas del mensaje                          │   │
│  │  • Explanation Count - Intentos de explicación por tema              │   │
│  │  • Confirmed Understanding - Si confirmó comprensión                 │   │
│  │  • Conversation History - Historial de mensajes                      │   │
│  │  • Guidance Attempts - Intentos de guía por tema                     │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  E - ENVIRONMENT (Entorno)                                            │   │
│  │  Contexto externo del agente:                                        │   │
│  │  • Course Context - Información del curso                            │   │
│  │  • User Context - Información del usuario                            │   │
│  │  • Curriculum Config - Configuración del currículo                   │   │
│  │  • Agent Config - Configuración del agente                           │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Arquitectura General

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          STUDENT SUPPORT AGENT                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐    ┌────────────┐ │
│  │   FRONTEND   │───>│   EXTERNAL   │───>│    AGENT     │───>│   OpenAI   │ │
│  │   chat.js    │    │   SERVICE    │    │  ORCHESTRATOR│    │   CLIENT   │ │
│  └──────────────┘    └──────────────┘    └──────────────┘    └────────────┘ │
│         │                   │                   │                   │        │
│         │                   │                   │                   │        │
│         v                   v                   v                   v        │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐    ┌────────────┐ │
│  │   TEMPLATE   │    │  CAPABILITIES│    │    MEMORY    │    │   TOOLS    │ │
│  │ chat.mustache│    │   & RULES    │    │  & STATE     │    │  REGISTRY  │ │
│  └──────────────┘    └──────────────┘    └──────────────┘    └────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## Ejecución del Framework GAME

El agente procesa cada mensaje siguiendo las 4 fases del framework GAME:

```
┌─────────────────────────────────────────────────────────────────┐
│                    GAME FRAMEWORK EXECUTION                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. ENVIRONMENT (Entorno)                                       │
│     ├── Capturar mensaje del usuario                            │
│     ├── Cargar contexto del curso                               │
│     ├── Cargar configuración del currículo                      │
│     └── Obtener historial de conversación                       │
│                                                                  │
│  2. MEMORY (Memoria)                                            │
│     ├── Detectar señales del mensaje (regex + LLM)              │
│     ├── Aplicar transición de estado cognitivo                  │
│     ├── Detectar intención y tema                               │
│     └── Actualizar intentos de guía                             │
│                                                                  │
│  3. GOAL (Objetivo)                                             │
│     ├── Evaluar reglas de integridad académica                  │
│     ├── Evaluar reglas de privacidad                            │
│     ├── Evaluar reglas de tono                                  │
│     └── Bloquear si viola objetivos pedagógicos                 │
│                                                                  │
│  4. ACTIONS (Acciones)                                          │
│     ├── Seleccionar acción via política central                 │
│     ├── Instanciar clase de acción                              │
│     ├── Ejecutar acción con modificadores                       │
│     └── Actualizar memoria con respuesta                        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Flujo de Datos Completo

### 1. Entrada del Usuario (Frontend)

```
┌────────────────────────────────────────────────────────────────┐
│                         FRONTEND                                │
├────────────────────────────────────────────────────────────────┤
│                                                                 │
│  [Usuario escribe mensaje]                                      │
│           │                                                     │
│           v                                                     │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  chat.js                                                 │   │
│  │  ├── Captura mensaje                                     │   │
│  │  ├── Valida entrada (max 2000 caracteres)               │   │
│  │  ├── Muestra indicador de "escribiendo..."              │   │
│  │  └── Envía via AJAX a send_message                      │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `amd/src/chat.js` - Manager del chat frontend
- `templates/chat.mustache` - Template de la interfaz

### 2. Servicio Externo (AJAX Endpoint)

```
┌────────────────────────────────────────────────────────────────┐
│                    EXTERNAL SERVICE                             │
├────────────────────────────────────────────────────────────────┤
│                                                                 │
│  [AJAX Request]                                                 │
│           │                                                     │
│           v                                                     │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  send_message.php                                        │   │
│  │  ├── Valida parámetros (courseID, sessionID, message)   │   │
│  │  ├── Verifica capacidades (local/student_support:use)   │   │
│  │  ├── Limpia mensaje (htmlspecialchars, trim)            │   │
│  │  ├── Crea instancia del agente                          │   │
│  │  └── Llama process_message()                            │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `classes/external/send_message.php` - Endpoint AJAX
- `db/services.php` - Definición del servicio

### 3. Orquestador del Agente (GAME Framework)

```
┌────────────────────────────────────────────────────────────────┐
│                    AGENT ORCHESTRATOR                           │
├────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │  student_support_agent.php                               │   │
│  │                                                          │   │
│  │  process_message($message)                               │   │
│  │  ├── is_ready() - Verificar configuración                │   │
│  │  │                                                       │   │
│  │  ├── 1. ENVIRONMENT                                      │   │
│  │  │   ├── gather_environment($message)                    │   │
│  │  │   ├── memory->add_message($message, 'user')          │   │
│  │  │   └── config->build_agent_context()                   │   │
│  │  │                                                       │   │
│  │  ├── 2. MEMORY                                           │   │
│  │  │   ├── update_memory($environment)                     │   │
│  │  │   ├── signal_detector->detect($message)              │   │
│  │  │   ├── transition_engine->apply_transition()          │   │
│  │  │   └── intent_detector->detect() [legacy]             │   │
│  │  │                                                       │   │
│  │  ├── 3. GOAL                                             │   │
│  │  │   ├── evaluate_goal($environment, $memorystate)      │   │
│  │  │   ├── Evaluar academic_integrity rules               │   │
│  │  │   ├── Evaluar privacy rules                          │   │
│  │  │   └── Evaluar tone rules                             │   │
│  │  │                                                       │   │
│  │  └── 4. ACTIONS                                          │   │
│  │      ├── execute_action($environment, $memorystate)     │   │
│  │      ├── action_policy->decide_next_action()            │   │
│  │      ├── action->execute_with_modifiers()               │   │
│  │      └── finalize_action_result()                       │   │
│  └─────────────────────────────────────────────────────────┘   │
│                                                                 │
└────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `classes/agent/student_support_agent.php` - Orquestador principal

---

## Detalle de Cada Componente GAME

### G - GOAL (Objetivo)

El Goal define las restricciones pedagógicas que el agente debe respetar:

```
┌─────────────────────────────────────────────────────────────────┐
│                         GOAL SYSTEM                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  ACADEMIC INTEGRITY                                      │    │
│  │  ├── Detecta solicitudes de soluciones completas        │    │
│  │  ├── Bloquea peticiones de hacer tareas                 │    │
│  │  ├── Bloquea patrones de trampa                         │    │
│  │  └── Bloquea asistencia en exámenes                     │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  PRIVACY                                                 │    │
│  │  ├── Valida que no se soliciten datos personales        │    │
│  │  └── Asegura cumplimiento GDPR                          │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  TONE                                                    │    │
│  │  ├── Asegura tono profesional pero cálido               │    │
│  │  └── Consistencia con enfoque pedagógico                │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  Resultado: {blocked: bool, reason: string, message: string}    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `classes/rules/rule_interface.php` - Interfaz de reglas
- `classes/rules/academic_integrity.php` - Integridad académica
- `classes/rules/privacy.php` - Privacidad
- `classes/rules/tone.php` - Tono

---

## Sistema Cognitivo de Dos Niveles (Two-Tier Cognitive Model)

El agente utiliza un **modelo cognitivo de dos niveles** para adaptar sus respuestas:

### Nivel 1: COGNITIVE PHASE (Tipo de respuesta)

La fase cognitiva determina **QUÉ TIPO** de respuesta pedagógica es apropiada:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         COGNITIVE PHASES                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  PHASE_NO_MODEL (Sin Modelo Mental)                                   │   │
│  │                                                                        │   │
│  │  El estudiante NO tiene comprensión fundacional del concepto.         │   │
│  │  NO puede razonar sobre el tema porque le falta la base.              │   │
│  │                                                                        │   │
│  │  RESPUESTA: Explicación directa y literal                             │   │
│  │  ├── NO preguntas (no puede razonar aún)                              │   │
│  │  ├── NO analogías (aumentan carga cognitiva)                          │   │
│  │  ├── NO verificación de comprensión                                   │   │
│  │  └── SOLO explicación literal y directa                               │   │
│  │                                                                        │   │
│  │  Acción: direct_explanation                                            │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  PHASE_PARTIAL_MODEL (Modelo Mental Parcial)                          │   │
│  │                                                                        │   │
│  │  El estudiante tiene una idea básica pero incompleta.                 │   │
│  │  Puede seguir explicaciones pero no explorar solo.                    │   │
│  │                                                                        │   │
│  │  RESPUESTA: Explicación con preguntas OPCIONALES                      │   │
│  │  ├── Preguntas son OPCIONALES (no obligatorias)                       │   │
│  │  ├── Analogías simples permitidas (con cuidado)                       │   │
│  │  ├── NO método socrático completo                                     │   │
│  │  └── NO problemas de práctica (no está listo)                         │   │
│  │                                                                        │   │
│  │  Acción: explain_concept con modifier optional_question               │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
│  ┌──────────────────────────────────────────────────────────────────────┐   │
│  │  PHASE_FUNCTIONAL_MODEL (Modelo Mental Funcional)                     │   │
│  │                                                                        │   │
│  │  El estudiante PUEDE razonar sobre el concepto.                       │   │
│  │  Tiene base suficiente para explorar y descubrir.                     │   │
│  │                                                                        │   │
│  │  RESPUESTA: Método socrático completo                                 │   │
│  │  ├── Preguntas guía HABILITADAS                                       │   │
│  │  ├── Analogías complejas permitidas                                   │   │
│  │  ├── Problemas de práctica disponibles                                │   │
│  │  └── Exploración y descubrimiento guiado                              │   │
│  │                                                                        │   │
│  │  Acciones: ask_guiding_question, give_practice_problem, etc.          │   │
│  └──────────────────────────────────────────────────────────────────────┘   │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Transiciones de Fase

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                      PHASE TRANSITIONS                                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   ┌──────────────┐                                                           │
│   │  NO_MODEL    │◄────────────────────────────────────────────────┐        │
│   └──────┬───────┘                                                  │        │
│          │                                                          │        │
│          │ Después de explicación                                   │        │
│          │ + señal ATTEMPTING                                       │        │
│          v                                                          │        │
│   ┌──────────────┐                                                  │        │
│   │ PARTIAL_MODEL│                                                  │        │
│   └──────┬───────┘                                                  │        │
│          │                                                          │        │
│          │ Confirma comprensión                                     │        │
│          │ o señal READY_TO_PRACTICE                                │        │
│          v                                                          │        │
│   ┌──────────────┐                                                  │        │
│   │FUNCTIONAL    │──────────────────────────────────────────────────┘        │
│   │   MODEL      │     Nuevo tema o señal LACKS_MENTAL_MODEL                 │
│   └──────────────┘                                                           │
│                                                                              │
│   REGLAS DE TRANSICIÓN:                                                      │
│   • LACKS_MENTAL_MODEL signal → Siempre vuelve a NO_MODEL                   │
│   • Primera interacción con tema → NO_MODEL                                  │
│   • Confusión después de 2+ explicaciones → NO_MODEL                        │
│   • Confirmación de comprensión → FUNCTIONAL_MODEL                          │
│   • Señal ATTEMPTING + 1 explicación → PARTIAL_MODEL                        │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Nivel 2: COGNITIVE STATE (Tono y enfoque)

El estado cognitivo determina el **TONO** y **ENFOQUE** dentro de la fase:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         COGNITIVE STATES                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│   Estados emocionales/situacionales:                                         │
│   • NEW_QUESTION    - Nueva pregunta del estudiante                          │
│   • EXPLORING       - Explorando activamente                                 │
│   • BLOCKED         - Atascado temporalmente                                 │
│   • FRUSTRATED      - Frustración emocional                                  │
│   • MAKING_PROGRESS - Avanzando bien                                         │
│   • READY_TO_CLOSE  - Listo para cerrar                                      │
│   • SEEKING_ANSWER  - Pidiendo respuesta directa                             │
│   • NEEDS_ESCALATION- Necesita profesor                                      │
│                                                                              │
│   La FASE determina QUÉ TIPO de respuesta.                                   │
│   El ESTADO determina el TONO de esa respuesta.                              │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Nueva Señal: LACKS_MENTAL_MODEL

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    SIGNAL: LACKS_MENTAL_MODEL                                 │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Detecta cuando el estudiante NO tiene modelo mental del concepto.           │
│                                                                              │
│  PATRONES (Inglés):                                                          │
│  • "I don't understand anything"                                             │
│  • "What is that?"                                                           │
│  • "I have no idea what..."                                                  │
│  • "Explain from the beginning"                                              │
│  • "Start from scratch"                                                      │
│  • "I've never heard of this"                                                │
│                                                                              │
│  PATRONES (Español):                                                         │
│  • "No entiendo nada"                                                        │
│  • "¿Qué es eso?"                                                            │
│  • "No tengo ni idea"                                                        │
│  • "Explícame desde el principio"                                            │
│  • "Desde cero"                                                              │
│                                                                              │
│  HEURÍSTICAS CONTEXTUALES:                                                   │
│  • Primera interacción + pregunta = NO_MODEL                                 │
│  • "everything" o "all of it" después de explicación = NO_MODEL              │
│  • Confusión después de 2+ explicaciones = modelo no se forma               │
│  • Respuesta vaga/circular después de explicación = atascado                 │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `classes/agent/cognitive_state.php` - Fases y estados cognitivos
- `classes/agent/state_transition_engine.php` - Motor de transiciones de fase
- `classes/agent/signal_detector.php` - Detección de LACKS_MENTAL_MODEL
- `classes/agent/agent_memory.php` - Tracking de explicaciones

---

### A - ACTIONS (Acciones)

Las acciones son las intervenciones pedagógicas que el agente puede realizar:

```
┌─────────────────────────────────────────────────────────────────┐
│                      ACTION SYSTEM                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  ACTION POLICY (Política Central)                        │    │
│  │                                                          │    │
│  │  Input: cognitive_state + signals + guidance_attempts    │    │
│  │         │                                                │    │
│  │         v                                                │    │
│  │  ┌───────────────────────────────────────────────────┐  │    │
│  │  │  BLOCKED state                                     │  │    │
│  │  │  ├── Múltiples bloqueos → micro_scaffold          │  │    │
│  │  │  └── Primer bloqueo → rephrase                    │  │    │
│  │  └───────────────────────────────────────────────────┘  │    │
│  │  ┌───────────────────────────────────────────────────┐  │    │
│  │  │  FRUSTRATED state                                  │  │    │
│  │  │  └── empathize + scaffold                         │  │    │
│  │  └───────────────────────────────────────────────────┘  │    │
│  │  ┌───────────────────────────────────────────────────┐  │    │
│  │  │  EXPLORING state                                   │  │    │
│  │  │  ├── ready_to_practice → give_practice_problem    │  │    │
│  │  │  ├── wants_example → give_example                 │  │    │
│  │  │  └── attempting → guide with acknowledgment       │  │    │
│  │  └───────────────────────────────────────────────────┘  │    │
│  │                                                          │    │
│  │  Output: {action, class, modifiers}                      │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  6 Acciones Pedagógicas:                                        │
│  ├── explain_concept      - Explicar conceptos                  │
│  ├── ask_guiding_question - Preguntas socráticas                │
│  ├── give_example         - Ejemplos ilustrativos               │
│  ├── rephrase_instruction - Reformular explicaciones            │
│  ├── give_practice_problem - Problemas de práctica              │
│  └── micro_scaffold       - Preguntas simples para atascados    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `classes/agent/action_policy.php` - Política central
- `classes/agent/action_selector.php` - Selector legacy
- `classes/agent/actions/*.php` - Implementación de acciones

---

## Detalle de Acciones Pedagógicas

Cada acción es un **CONTROLLED RENDERER** (renderizador controlado), NO un agente. Las acciones:
- NO razonan libremente
- NO deciden estrategia pedagógica
- SOLO generan texto bajo restricciones estrictas

### Principio de Aislamiento de Contexto

```
┌─────────────────────────────────────────────────────────────────┐
│                    CONTEXT ISOLATION                             │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Durante la ejecución de acciones:                              │
│                                                                  │
│  ❌ NO pasar el system prompt global                            │
│  ❌ NO pasar el historial completo de conversación              │
│  ❌ NO pasar reglas, objetivos o lista de herramientas          │
│  ✅ SOLO pasar instrucciones mínimas específicas de la acción   │
│                                                                  │
│  Esto previene:                                                  │
│  • Jailbreaking                                                  │
│  • Filtración de contexto                                        │
│  • Comportamiento impredecible                                   │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### System Prompt Base (Aislado)

Todas las acciones usan este system prompt mínimo. **NOTA:** Las preguntas son ahora **CONDICIONALES** según la fase cognitiva:

```
You are generating a controlled educational response.

ROLE: Educational text generator (NOT a decision-making agent).

OUTPUT CONSTRAINTS (MANDATORY):
- Write for a {grade_level} student
- Respond in {language}
- Maximum 3 short paragraphs
- No numbered lists with more than 3 items
- No bullet points
- Explain ONE concept only
- Do NOT provide complete answers or solutions
- Do NOT cover the full topic

STYLE:
- Professional but warm
- Simple, clear language
- Short sentences

You are a text renderer, not a teacher making decisions.
```

**IMPORTANTE:** La regla "End with EXACTLY ONE question" fue ELIMINADA del system prompt base. Ahora cada acción decide si incluir preguntas basándose en los **modifiers** que recibe del `action_policy`:

| Modifier | Comportamiento |
|----------|---------------|
| `no_questions` | NO incluir preguntas (NO_MODEL phase) |
| `optional_question` | Pregunta es opcional (PARTIAL_MODEL phase) |
| (ninguno) | Pregunta es obligatoria (FUNCTIONAL_MODEL phase) |

---

### 0. direct_explanation (Explicación Directa) - NUEVO

**Propósito:** Explicación FUNDACIONAL para estudiantes que NO tienen modelo mental del concepto.

**Cuándo se usa:**
- Fase `NO_MENTAL_MODEL` (siempre)
- Señal `LACKS_MENTAL_MODEL` detectada
- Primera interacción con un tema nuevo
- Confusión total después de múltiples explicaciones

**RESTRICCIONES CRÍTICAS (NO NEGOCIABLES):**
- ❌ NO preguntas (el estudiante no puede razonar aún)
- ❌ NO analogías/metáforas (aumentan carga cognitiva)
- ❌ NO verificación de comprensión
- ❌ NO lenguaje como "piensa en" o "considera"
- ✅ SOLO explicación literal y directa

**Instrucción al LLM:**

```
Topic: {topic}
Student said: "{studentmessage}"

The student has NO mental model of {topic}. They cannot reason about it yet.

TASK: Explain {topic} directly and literally.

MANDATORY RULES:
- Maximum 5 sentences total
- Sentence 1: Define what {topic} IS (one clear, literal definition)
- Sentences 2-3: Explain the core mechanism or rule in simple terms
- Sentences 4-5: Give ONE concrete, literal example
- Use ONLY declarative sentences
- No metaphors, no analogies, no comparisons
- No questions of any kind
- No verification requests

FORBIDDEN PHRASES (DO NOT USE):
- "Think about..."
- "Can you see..."
- "Does that make sense?"
- "It's like..." (analogy)
- "Imagine..."
- "Consider..."
- Any question mark (?)

GOOD EXAMPLE OUTPUT:
"Fractions are numbers that show parts of a whole. When something is divided
into equal pieces, a fraction tells you how many pieces you have. The top
number shows how many pieces you took. The bottom number shows how many total
pieces there are. If you cut a pizza into 4 slices and take 1 slice, you have
1/4 of the pizza."

Write a direct, literal explanation now (5 sentences max, NO QUESTIONS):
```

**Archivo:** `classes/agent/actions/direct_explanation.php`

---

### 1. explain_concept (Explicar Concepto)

**Propósito:** Explicar UN concepto fundamental de forma breve y enfocada.

**Cuándo se usa:**
- Fase `PARTIAL_MODEL` o `FUNCTIONAL_MODEL`
- Estado `NEW_QUESTION` + señal `CONFUSION`
- Estado `MAKING_PROGRESS` + señal `NEW_QUESTION`
- Enfoque `SCAFFOLDED` por defecto

**Comportamiento de Preguntas (CONDICIONAL según fase):**

| Modifier | Comportamiento | Fase |
|----------|---------------|------|
| `no_questions` | Sin pregunta final | NO_MODEL |
| `optional_question` | Pregunta opcional | PARTIAL_MODEL |
| (ninguno) | Pregunta obligatoria | FUNCTIONAL_MODEL |

**Instrucción al LLM (con preguntas condicionales):**

```
Topic: {concept}
Student said: "{studentmessage}"
{context_block}

TASK: Explain ONE small part of {concept} simply.

RULES:
- Maximum {sentence_count} sentences total
- Sentence 1: Acknowledge what student said (max 10 words)
- Sentences 2-3: Explain ONE aspect of {concept} simply
{question_rule}
- Stay on {concept} - do NOT change topics
- If student said "everything" or similar, they mean about {concept}

Write your response now (max {sentence_count} sentences):
```

Donde `{question_rule}` varía según el modifier:
- `no_questions`: "- Do NOT ask any questions - just explain"
- `optional_question`: "- You MAY optionally end with a brief question, but it is not required"
- (default): "- Final sentence: Ask ONE question to check understanding"

**Restricciones:**
- Máximo 3-4 oraciones según la fase
- UNA idea fundamental solamente
- Pregunta es CONDICIONAL según fase cognitiva
- NO cobertura completa del tema
- NO explicaciones estilo libro de texto

**Archivo:** `classes/agent/actions/explain_concept.php`

---

### 2. ask_guiding_question (Pregunta Guía Socrática)

**Propósito:** Generar exactamente UNA pregunta socrática para guiar el pensamiento.

**Cuándo se usa:**
- Estado `EXPLORING` + señal `ATTEMPTING`
- Estado `MAKING_PROGRESS` (para profundizar)
- Enfoque `SOCRATIC` por defecto
- Estado `BLOCKED` (con modificador `simple`)

**Instrucción al LLM:**

```
Topic: {concept}
Student said: "{studentmessage}"
{context_block}

TASK: Ask ONE {style} question about {concept}.

RULES:
- Maximum 2 sentences total
- First sentence: brief acknowledgment (optional, max 10 words)
- Second sentence: ONE question about {concept}
- The question must help the student think about {concept}
- Do NOT explain anything
- Do NOT change topics

OUTPUT FORMAT EXAMPLE:
"I see you're working on [topic]. What do you think is the first step to [specific aspect]?"

Write your response now (max 2 sentences):
```

**Modificadores disponibles:**
- `acknowledge_attempt`: Reconocer el intento del estudiante
- `gentle`: Pregunta más suave
- `simple`: Pregunta muy simple
- `micro` + `closed_question`: Pregunta sí/no para estudiantes atascados
- `check_deeper`: Profundizar en comprensión
- `redirect`: Redirigir de solicitud de respuesta

**Buenos patrones de preguntas:**
- "¿Qué crees que pasa cuando...?"
- "¿Cómo describirías... con tus propias palabras?"
- "¿Cuál es la relación entre X e Y?"
- "¿Por qué crees que...?"

**Restricciones:**
- Máximo 2 oraciones cortas de contexto
- Exactamente UNA pregunta guía
- NO explicaciones
- NO respuestas disfrazadas de pistas

**Archivo:** `classes/agent/actions/ask_guiding_question.php`

---

### 3. give_example (Dar Ejemplo)

**Propósito:** Dar UN ejemplo análogo breve para ilustrar un concepto.

**Cuándo se usa:**
- Señal `WANTS_EXAMPLE` en cualquier estado
- Estado `NEW_QUESTION` + enfoque `EXPLORATORY`
- Estado `BLOCKED` + señal `WANTS_EXAMPLE`

**Instrucción al LLM:**

```
Topic: {concept}
Student said: "{studentmessage}"
{context_block}

TASK: Give ONE simple example about {concept}.

RULES:
- Maximum 5 sentences total
- Sentence 1: Brief intro to example (max 10 words)
- Sentences 2-3: Show the example with simple numbers/scenario
- Sentence 4: Show the method/reasoning briefly
- Sentence 5: Ask ONE question about applying this
- The example must be about {concept}, NOT a different topic
- Use DIFFERENT numbers than their problem

OUTPUT FORMAT EXAMPLE:
"Here's an example. If we have [scenario], we would [method].
So [result]. Can you see how this applies to your problem?"

Write your response now (max 5 sentences):
```

**Restricciones:**
- UN ejemplo simple solamente
- DIFERENTE del problema real del estudiante
- Máximo 3 párrafos cortos
- Muestra el MÉTODO, no la respuesta
- Termina con UNA pregunta

**Reglas críticas:**
- Usar un ejemplo DIFERENTE, NO el problema actual
- Mostrar el MÉTODO, no la respuesta
- Si preguntan sobre problema X, demostrar con problema Y

**Archivo:** `classes/agent/actions/give_example.php`

---

### 4. rephrase_instruction (Reformular Explicación)

**Propósito:** Reformular un concepto usando palabras diferentes, analogía o perspectiva.

**Cuándo se usa:**
- Estado `BLOCKED` (primer bloqueo)
- Estado `EXPLORING` + señal `NEEDS_CLARIFICATION`
- Estado `EXPLORING` + señal `UNCERTAINTY` (después de 2+ intentos)
- Estado `FRUSTRATED` (con modificadores de empatía)

**Instrucción al LLM:**

```
Topic: {concept}
Student said: "{studentmessage}"
{context_block}
{empathy_note}

TASK: Re-explain {concept} using DIFFERENT, SIMPLER words.

RULES:
- Maximum 3 sentences total
- Sentence 1: Brief acknowledgment (max 8 words)
- Sentence 2-3: Simple re-explanation of {concept} using an analogy or simpler words
- End with ONE short question to check understanding
- Stay on topic: {concept}
- Do NOT explain new concepts

OUTPUT FORMAT EXAMPLE:
"Let me try explaining differently. [Simple analogy or rephrasing].
Does that make more sense?"

Write your response now (max 4 sentences):
```

**Modificadores disponibles:**
- `empathetic`: Tono empático
- `acknowledge_frustration`: Reconocer frustración
- `simple_scaffold`: Andamiaje simple
- `escalate` + `suggest_teacher`: Sugerir profesor

**Estrategias de reformulación (elegir UNA):**
- Usar palabras cotidianas más simples
- Probar una analogía o comparación diferente
- Enfocarse en UN aspecto de confusión
- Usar oraciones más cortas

**Manejo de pushback:**
Cuando el estudiante corrige al tutor, se usa una instrucción especial:

```
SITUATION: The student is correcting you or pointing out that something
you said was YOUR idea, not theirs.

TASK: Acknowledge the correction gracefully and refocus on helping them.

RULES:
- Maximum 2 sentences total
- Sentence 1: Briefly acknowledge their point
  (e.g., "You're right, that was my analogy.")
- Sentence 2: Ask what specifically they need help with about {concept}
- Do NOT repeat the analogy or idea they objected to
- Do NOT be defensive
- Be humble and redirect to their needs
```

**Archivo:** `classes/agent/actions/rephrase_instruction.php`

---

### 5. give_practice_problem (Dar Problema de Práctica)

**Propósito:** Dar UN problema de práctica para que el estudiante resuelva.

**Cuándo se usa:**
- Estado `EXPLORING` + señal `READY_TO_PRACTICE`
- Estado `MAKING_PROGRESS` + señal `READY_TO_PRACTICE`

**Instrucción al LLM:**

```
Topic: {concept}
Student said: "{studentmessage}"

TASK: Give ONE practice problem about {concept} for the student to solve.

RULES:
- Maximum 3 sentences total
- Sentence 1: Brief encouragement (max 8 words)
- Sentence 2: State the problem clearly with simple numbers
- Sentence 3: Invite them to try it
- Do NOT give the answer
- Do NOT give hints
- Problem must be about {concept}

OUTPUT FORMAT EXAMPLE:
"Great, let's try one! What is 3 × 4? Give it a try and tell me what you get."

Write your response now (max 3 sentences):
```

**Diferencia con give_example:**
- `give_example`: Muestra un ejemplo RESUELTO para ilustrar método
- `give_practice_problem`: Da un problema SIN RESOLVER para que practique

**Restricciones:**
- UN problema simple para resolver
- Relacionado con el tema actual
- Nivel de dificultad apropiado
- Instrucciones claras
- Ánimo para intentar
- NO dar respuesta
- NO dar pistas

**Archivo:** `classes/agent/actions/give_practice_problem.php`

---

### 6. micro_scaffold (Micro-Andamiaje)

**Propósito:** Hacer preguntas muy simples y cerradas para estudiantes muy atascados.

**Cuándo se usa:**
- Estado `BLOCKED` con `blocked_count >= 2`

**Implementación:**
Usa la clase `ask_guiding_question` con modificadores especiales:

```php
return [
    'action' => self::ACTION_MICRO_SCAFFOLD,
    'class' => ask_guiding_question::class,
    'modifiers' => [
        'micro' => true,
        'closed_question' => true,  // Pregunta sí/no
        'very_simple' => true,
    ],
];
```

**Resultado:**
Genera preguntas de tipo sí/no muy simples en lugar de preguntas abiertas.

---

## Flujo de Selección de Acciones

```
┌─────────────────────────────────────────────────────────────────┐
│                ACTION POLICY: decide_next_action()               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  Input: cognitive_state + signals + guidance_attempts            │
│                                                                  │
│  switch (cognitive_state):                                       │
│                                                                  │
│    case NEEDS_ESCALATION:                                        │
│      └── return action_escalate()                               │
│                                                                  │
│    case FRUSTRATED:                                              │
│      └── return action_empathize_scaffold()                     │
│          ├── class: rephrase_instruction                        │
│          └── modifiers: empathetic, acknowledge_frustration     │
│                                                                  │
│    case SEEKING_ANSWER:                                          │
│      └── return action_redirect()                               │
│          ├── class: ask_guiding_question                        │
│          └── modifiers: redirect, acknowledge_request           │
│                                                                  │
│    case READY_TO_CLOSE:                                          │
│      └── return action_close()                                  │
│                                                                  │
│    case BLOCKED:                                                 │
│      ├── if blocked_count >= 2:                                 │
│      │   └── return action_micro_scaffold()                     │
│      ├── if WANTS_EXAMPLE signal:                               │
│      │   └── return action_example()                            │
│      ├── if blocked_count == 1:                                 │
│      │   └── return action_rephrase()                           │
│      └── default:                                                │
│          └── return action_guide(simple: true)                  │
│                                                                  │
│    case NEW_QUESTION:                                            │
│      ├── if WANTS_EXAMPLE signal:                               │
│      │   └── return action_example()                            │
│      ├── if CONFUSION signal:                                   │
│      │   └── return action_explain()                            │
│      └── switch (approach):                                      │
│          ├── SOCRATIC: return action_guide()                    │
│          ├── EXPLORATORY: return action_example()               │
│          └── SCAFFOLDED: return action_explain()                │
│                                                                  │
│    case EXPLORING:                                               │
│      ├── if READY_TO_PRACTICE signal:                           │
│      │   └── return action_practice_problem()                   │
│      ├── if NEEDS_CLARIFICATION signal:                         │
│      │   └── return action_rephrase()                           │
│      ├── if WANTS_EXAMPLE signal:                               │
│      │   └── return action_example()                            │
│      ├── if ATTEMPTING signal:                                  │
│      │   └── return action_guide(acknowledge_attempt: true)     │
│      ├── if UNCERTAINTY signal:                                 │
│      │   ├── if attempts >= 2: return action_rephrase()         │
│      │   └── else: return action_guide(gentle: true)            │
│      └── switch (approach):                                      │
│          ├── SOCRATIC: return action_guide()                    │
│          ├── EXPLORATORY: alternate example/guide               │
│          └── SCAFFOLDED: return action_explain()                │
│                                                                  │
│    case MAKING_PROGRESS:                                         │
│      ├── if READY_TO_PRACTICE signal:                           │
│      │   └── return action_practice_problem()                   │
│      ├── if CONFIRMS_UNDERSTANDING signal:                      │
│      │   └── return action_guide(check_deeper: true)            │
│      ├── if NEW_QUESTION signal:                                │
│      │   └── return action_explain()                            │
│      └── default:                                                │
│          └── return action_guide(build_on_progress: true)       │
│                                                                  │
│    default:                                                      │
│      └── return action_guide()                                  │
│                                                                  │
│  Output: {action: string, class: class_name, modifiers: array}  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Ejecución de Acciones

```
┌─────────────────────────────────────────────────────────────────┐
│                    ACTION EXECUTION FLOW                         │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. action_policy->decide_next_action()                         │
│     └── Returns: {action, class, modifiers}                     │
│                                                                  │
│  2. action_policy->instantiate_action(decision)                 │
│     └── Returns: action_interface instance                      │
│                                                                  │
│  3. action->execute_with_modifiers(modifiers, env, memory, ...)│
│     │                                                            │
│     ├── get_isolated_system_prompt(config)                      │
│     │   └── Minimal system prompt (NOT global agent prompt)     │
│     │                                                            │
│     ├── build_isolated_user_prompt(context, analysis, ...)      │
│     │   └── Action-specific instruction (see above)             │
│     │                                                            │
│     ├── openai_client->ask(system_prompt, messages, tools=[])   │
│     │   └── NO tools - pure text generation                     │
│     │                                                            │
│     └── handle_llm_response(response)                           │
│         └── Returns: {success, message, metadata}               │
│                                                                  │
│  4. finalize_action_result(action, result, env, memory)         │
│     ├── Add assistant message to memory                         │
│     ├── Increment guidance attempts if guidance action          │
│     ├── Update cognitive state                                  │
│     └── Save state                                              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

### M - MEMORY (Memoria)

La memoria rastrea el estado de la conversación y el progreso del estudiante:

```
┌─────────────────────────────────────────────────────────────────┐
│                      MEMORY SYSTEM                               │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  COGNITIVE STATE (Estado Cognitivo)                      │    │
│  │                                                          │    │
│  │  Rastrea DÓNDE está el estudiante en su aprendizaje:    │    │
│  │                                                          │    │
│  │    NEW_QUESTION ──> EXPLORING ──> MAKING_PROGRESS       │    │
│  │                         │              │                 │    │
│  │                    [frustrated]    [ready]               │    │
│  │                         │              │                 │    │
│  │                         v              v                 │    │
│  │                    FRUSTRATED    READY_TO_CLOSE          │    │
│  │                                                          │    │
│  │    [stuck] ──> BLOCKED ──> EXPLORING                     │    │
│  │                                                          │    │
│  │    [pide respuesta] ──> SEEKING_ANSWER ──> EXPLORING     │    │
│  │                                                          │    │
│  │    [múltiples fallos] ──> NEEDS_ESCALATION               │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  SIGNAL DETECTION (Detección de Señales)                 │    │
│  │                                                          │    │
│  │  11 Tipos de Señales (Boolean flags):                    │    │
│  │  ├── ANSWER_REQUEST      - Pide respuesta directa        │    │
│  │  ├── CONFUSION           - No entiende                   │    │
│  │  ├── FRUSTRATION         - Frustración emocional         │    │
│  │  ├── WANTS_EXAMPLE       - Pide ejemplo                  │    │
│  │  ├── CONFIRMS_UNDERSTANDING - Confirma comprensión       │    │
│  │  ├── CLOSING             - Quiere cerrar                 │    │
│  │  ├── NEW_QUESTION        - Nueva pregunta                │    │
│  │  ├── UNCERTAINTY         - Incertidumbre                 │    │
│  │  ├── NEEDS_CLARIFICATION - Pide aclaración               │    │
│  │  ├── ATTEMPTING          - Intenta responder             │    │
│  │  └── READY_TO_PRACTICE   - Quiere practicar              │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  AGENT MEMORY (Memoria del Agente)                       │    │
│  │                                                          │    │
│  │  ├── session_id          - ID único de conversación      │    │
│  │  ├── user_id, course_id  - Contexto                      │    │
│  │  ├── current_state       - Estado actual                 │    │
│  │  ├── guidance_attempts   - Intentos por tema             │    │
│  │  ├── current_topic       - Tema actual                   │    │
│  │  └── message_history     - Historial de mensajes         │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  Persistencia:                                                   │
│  ├── Cache Layer  - Acceso rápido en memoria                    │
│  └── Database     - Almacenamiento persistente                  │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `classes/agent/cognitive_state.php` - Estados cognitivos
- `classes/agent/state_transition_engine.php` - Motor de transiciones
- `classes/agent/signal_detector.php` - Detector híbrido
- `classes/agent/agent_memory.php` - Gestión de memoria

---

### E - ENVIRONMENT (Entorno)

El entorno proporciona todo el contexto externo necesario:

```
┌─────────────────────────────────────────────────────────────────┐
│                    ENVIRONMENT SYSTEM                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  COURSE CONTEXT                                          │    │
│  │  ├── course_id         - ID del curso                    │    │
│  │  ├── course_name       - Nombre del curso                │    │
│  │  └── course_category   - Categoría                       │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  USER CONTEXT                                            │    │
│  │  ├── user_id           - ID del usuario                  │    │
│  │  ├── user_name         - Nombre del usuario              │    │
│  │  └── user_role         - Rol (estudiante, profesor)      │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  CURRICULUM CONFIG                                       │    │
│  │  ├── curriculum_name   - Nombre del currículo            │    │
│  │  ├── grade_level       - Nivel de grado                  │    │
│  │  ├── subject_area      - Área de materia                 │    │
│  │  └── pedagogical_approach - Enfoque pedagógico           │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  AGENT CONFIG                                            │    │
│  │  ├── api_endpoint      - Endpoint de API                 │    │
│  │  ├── api_key           - Clave de API                    │    │
│  │  ├── model             - Modelo LLM                      │    │
│  │  ├── temperature       - Temperatura                     │    │
│  │  ├── max_tokens        - Tokens máximos                  │    │
│  │  └── max_attempts      - Intentos máximos de guía        │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Archivos involucrados:**
- `classes/agent/agent_config.php` - Gestión de configuración
- `settings.php` - UI de configuración admin

---

## Integración con OpenAI

```
┌─────────────────────────────────────────────────────────────────┐
│                      AI INTEGRATION                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  openai_client.php                                       │    │
│  │  ├── Envía requests a OpenAI Chat Completions API       │    │
│  │  ├── Maneja respuestas (texto o tool calls)             │    │
│  │  └── NO ejecuta acciones ni toma decisiones             │    │
│  └─────────────────────────────────────────────────────────┘    │
│                     │                                            │
│                     v                                            │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  tool_registry.php                                       │    │
│  │  ├── Mapea acciones a funciones OpenAI                  │    │
│  │  ├── Define schemas JSON para parámetros                │    │
│  │  └── Valida llamadas a herramientas                     │    │
│  └─────────────────────────────────────────────────────────┘    │
│                     │                                            │
│                     v                                            │
│  ┌─────────────────────────────────────────────────────────┐    │
│  │  function_call_handler.php                               │    │
│  │  ├── Procesa respuestas del LLM                         │    │
│  │  ├── Valida y prepara tool calls para ejecución         │    │
│  │  └── Maneja respuestas de texto directas                │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

**Principio de Aislamiento de Contexto:**
- Las acciones ejecutan con contexto **MÍNIMO**
- NO incluyen system prompt global
- NO incluyen historial de conversación
- SOLO instrucciones específicas de la acción

**Archivos involucrados:**
- `classes/ai/openai_client.php` - Cliente API
- `classes/ai/tool_registry.php` - Registro de herramientas
- `classes/ai/function_call_handler.php` - Manejador de funciones
- `classes/agent/prompts/system_prompt.php` - Constructor de prompts

---

## Sistema de Decisión por Capas

El agente usa **tres capas de fallback** para selección de acciones:

```
┌─────────────────────────────────────────────────────────────────┐
│                  THREE-TIER DECISION SYSTEM                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  TIER 1: STATE-DRIVEN POLICY (Primario)                         │
│  ├── Más rápido y controlado                                    │
│  ├── Decisión determinística basada en estado + señales         │
│  └── action_policy::decide_next_action()                        │
│         │                                                        │
│         │ (si no aplica)                                        │
│         v                                                        │
│  TIER 2: LLM ROUTING (Fallback)                                 │
│  ├── Usa function calling para selección de herramientas        │
│  ├── system_prompt::build() incluye tools                       │
│  └── openai_client::ask() con tools                             │
│         │                                                        │
│         │ (si falla)                                            │
│         v                                                        │
│  TIER 3: RULE-BASED ROUTING (Fallback final)                    │
│  ├── Sistema legacy basado en intención                         │
│  ├── action_selector::select()                                  │
│  └── Siempre disponible como respaldo                           │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Diagrama de Secuencia Completo

```
Usuario          Frontend         External        Agent           Signal         Policy         Action         OpenAI
  │                │              Service           │             Detector          │              │              │
  │  escribe msg   │                │               │                │              │              │              │
  │───────────────>│                │               │                │              │              │              │
  │                │   AJAX call    │               │                │              │              │              │
  │                │───────────────>│               │                │              │              │              │
  │                │                │  validate     │                │              │              │              │
  │                │                │───────────────│                │              │              │              │
  │                │                │               │                │              │              │              │
  │                │                │  process_msg  │                │              │              │              │
  │                │                │──────────────>│                │              │              │              │
  │                │                │               │                │              │              │              │
  │                │                │               │  ENVIRONMENT   │              │              │              │
  │                │                │               │  gather context│              │              │              │
  │                │                │               │                │              │              │              │
  │                │                │               │  MEMORY        │              │              │              │
  │                │                │               │───────────────>│              │              │              │
  │                │                │               │   detect()     │              │              │              │
  │                │                │               │<───────────────│              │              │              │
  │                │                │               │   signals      │              │              │              │
  │                │                │               │                │              │              │              │
  │                │                │               │  GOAL          │              │              │              │
  │                │                │               │  evaluate rules│              │              │              │
  │                │                │               │                │              │              │              │
  │                │                │               │  ACTIONS       │              │              │              │
  │                │                │               │────────────────────────────-->│              │              │
  │                │                │               │   decide_next_action()        │              │              │
  │                │                │               │<──────────────────────────────│              │              │
  │                │                │               │   {action, modifiers}         │              │              │
  │                │                │               │                │              │              │              │
  │                │                │               │──────────────────────────────────────────────>│              │
  │                │                │               │   execute_with_modifiers()    │              │              │
  │                │                │               │                │              │              │   ask()      │
  │                │                │               │                │              │              │─────────────>│
  │                │                │               │                │              │              │<─────────────│
  │                │                │               │                │              │              │   response   │
  │                │                │               │<──────────────────────────────────────────────│              │
  │                │                │               │   action_result│              │              │              │
  │                │                │               │                │              │              │              │
  │                │                │               │   save memory  │              │              │              │
  │                │                │               │   update state │              │              │              │
  │                │                │<──────────────│                │              │              │              │
  │                │                │   response    │                │              │              │              │
  │                │<───────────────│               │                │              │              │              │
  │                │   JSON response│               │                │              │              │              │
  │<───────────────│                │               │                │              │              │              │
  │   muestra msg  │                │               │                │              │              │              │
  │                │                │               │                │              │              │              │
```

---

## Estructura de Archivos

```
local_student_support/
├── index.php                          # Punto de entrada - interfaz chat
├── lib.php                            # Integración navegación de curso
├── settings.php                       # UI configuración admin
├── version.php                        # Metadatos del plugin
├── styles.css                         # Estilos del chat
│
├── db/
│   ├── access.php                     # Capacidades/roles
│   ├── install.xml                    # Schema de base de datos
│   ├── services.php                   # Definición servicios externos
│   └── caches.php                     # Definiciones de caché
│
├── classes/
│   ├── agent/
│   │   ├── student_support_agent.php  # ORQUESTADOR PRINCIPAL (GAME)
│   │   ├── agent_config.php           # ENVIRONMENT - Configuración
│   │   ├── agent_memory.php           # MEMORY - Estado e historial
│   │   ├── cognitive_state.php        # MEMORY - Estado de aprendizaje
│   │   ├── state_transition_engine.php # MEMORY - Transiciones
│   │   ├── signal_detector.php        # MEMORY - Detección híbrida
│   │   ├── llm_signal_classifier.php  # MEMORY - Clasificador LLM
│   │   ├── intent_detector.php        # MEMORY - Detección legacy
│   │   ├── action_selector.php        # ACTIONS - Selección legacy
│   │   ├── action_policy.php          # ACTIONS - Política central
│   │   ├── prompts/
│   │   │   └── system_prompt.php      # Constructor de prompts
│   │   └── actions/
│   │       ├── action_interface.php   # ACTIONS - Contrato
│   │       ├── base_action.php        # ACTIONS - Base con aislamiento
│   │       ├── direct_explanation.php # ACTIONS - Explicación directa (NO_MODEL)
│   │       ├── explain_concept.php    # ACTIONS - Explicar (con preguntas cond.)
│   │       ├── ask_guiding_question.php # ACTIONS - Guiar (FUNCTIONAL_MODEL)
│   │       ├── give_example.php       # ACTIONS - Ejemplo
│   │       ├── rephrase_instruction.php # ACTIONS - Reformular
│   │       └── give_practice_problem.php # ACTIONS - Practicar
│   │
│   ├── ai/
│   │   ├── openai_client.php          # Comunicación API OpenAI
│   │   ├── tool_registry.php          # Definición de tools
│   │   └── function_call_handler.php  # Procesa tool calls
│   │
│   ├── rules/
│   │   ├── rule_interface.php         # GOAL - Contrato de regla
│   │   ├── academic_integrity.php     # GOAL - Integridad académica
│   │   ├── privacy.php                # GOAL - Privacidad
│   │   └── tone.php                   # GOAL - Tono
│   │
│   ├── external/
│   │   └── send_message.php           # Endpoint AJAX
│   │
│   └── privacy/
│       └── provider.php               # Proveedor GDPR
│
├── amd/
│   ├── src/
│   │   └── chat.js                    # Manager del chat frontend
│   └── build/
│       ├── chat.min.js                # Versión minificada
│       └── chat.min.js.map            # Source map
│
├── templates/
│   └── chat.mustache                  # Template UI del chat
│
├── lang/
│   └── en/
│       └── local_student_support.php  # Cadenas en inglés
│
└── dev/
    ├── test_agent_config.php          # Testing de config
    ├── test_agent_entrypoint.php      # Testing del agente
    └── test_system_prompt.php         # Testing de prompts
```

---

## Principios de Arquitectura

### GAME Framework Balance

| Componente | Responsabilidad | Control |
|------------|-----------------|---------|
| **Goal** | Define restricciones pedagógicas | PHP (reglas) |
| **Actions** | Ejecuta intervenciones pedagógicas | PHP (política) + LLM (contenido) |
| **Memory** | Rastrea estado y señales | PHP (estado) + LLM (clasificación ambigua) |
| **Environment** | Provee contexto | PHP (configuración) |

### Control vs Libertad

| PHP Controla | LLM Tiene Libertad |
|--------------|-------------------|
| Señales | Generación de texto |
| Estados | Clasificación ambigua |
| Políticas | Contenido de respuestas |
| Reglas (Goal) | Creatividad en ejemplos |
| Restricciones | Estilo de explicación |

### Aislamiento de Contexto

Las acciones ejecutan con **contexto mínimo**:
- NO system prompt global
- NO historial de conversación completo
- NO herramientas del agente
- SOLO instrucciones específicas de la acción

Esto previene:
- Jailbreaking
- Filtración de contexto
- Comportamiento impredecible

---

## Resumen

Este plugin implementa un **agente educativo sofisticado** usando el **Framework GAME**:

1. **Goal** - Define las restricciones pedagógicas (nunca dar respuestas directas)
2. **Actions** - Proporciona intervenciones pedagógicas estructuradas
3. **Memory** - Rastrea el estado cognitivo y progreso del estudiante
4. **Environment** - Provee todo el contexto necesario para decisiones

La arquitectura balancea elegantemente las **capacidades del AI** (generación de texto, clasificación ambigua) con el **control humano** (las políticas PHP manejan todas las decisiones estratégicas).
