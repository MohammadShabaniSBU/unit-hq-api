# Internal agents

Part of the customer-facing agent docs. Map: [README](README.md).


## Internal agents

Three employee agents sit on the Laravel AI SDK. They are not the
customer-facing runtime. None of them uses `AgentRuntime` or
`agent_conversations`.

Code lives in `app/Ai/Agents/`, `app/Ai/Tools/`, and `app/Ai/Middleware/MetersUsage.php`. New agents and tools scaffold from `stubs/`.

`CrmCopilotAgent` is the employee sidebar (`ai_purpose = copilot`).
`SummaryAgent` writes CRM summaries (`ai_purpose = summarize`).
`TemplateAssistantAgent` edits an email in the builder, Blocks mode only
(`ai_purpose = template_assist`).

They share the SDK conversation store: `copilot_conversations` and
`copilot_conversation_messages` (`config/ai.php`). An assist session links
a conversation to a template family and a locale. The copilot sidebar
excludes linked conversations. `GenerateCopilotConversationTitle` meters
a fourth purpose, `title`.

Detail for the template assistant: `../06-communications.md`.

