# Jinx Assistant proof of concept

This branch connects the existing Case Assistant panel on each Jinx lead page to a persistent AI backend.

## What this proves

- One visible assistant inside the existing lead screen.
- Per-user, per-case conversation history.
- Shared organisational knowledge that survives across cases.
- New rules are proposed first and only stored after the packager confirms them.
- The assistant can use current lead data already held by Jinx.
- The assistant can surface potentially similar previous Jinx Assistant cases.
- Conversation summaries are retained to make previous-case retrieval more useful over time.

## Setup

Add to the server `.env`:

```env
JINX_ASSISTANT_API_KEY=your_api_key_here
JINX_ASSISTANT_MODEL=gpt-5.6-terra
```

Then run:

```bash
php artisan migrate
php artisan config:clear
php artisan route:clear
```

Do not commit the real API key.

## Test the learning behaviour

1. Open any lead in Jinx.
2. In the existing Jinx Assistant panel type:

   `Our IP Smith Insolvency now accepts birth certificates as ID.`

3. The assistant should treat this as a potential durable criterion and ask for confirmation rather than silently changing shared knowledge.
4. Reply `Yes, apply that to all Smith Insolvency cases.`
5. The UI should show that the rule was remembered.
6. Open a different lead and ask:

   `Will Smith Insolvency accept a birth certificate as ID?`

7. The assistant should answer using the shared rule saved from the first case.

## Test case memory

Have a conversation on one lead explaining an unusual scenario, for example a client living with parents with no rent and mixed UC/employment income. On another lead, ask about a similar scenario. Keyword-based retrieval provides up to three potentially similar prior conversations to the model, identified by Jinx lead ID where available.

This first implementation intentionally uses simple database retrieval rather than embeddings/vector search. If the interaction proves useful, semantic retrieval should be the next upgrade.

## Data tables

- `assistant_knowledge_items` — durable company, partner and IP knowledge.
- `assistant_conversations` — case/user conversations and rolling summaries.
- `assistant_messages` — user/assistant history.

## Guardrails in this POC

- Client-specific facts are not supposed to be promoted into shared organisational knowledge.
- New durable criteria require conversational confirmation.
- Knowledge records retain the user who created them and the originating conversation ID.
- Shared knowledge remains even when a case chat is reset.
- The AI has no direct database write access; Laravel controls all writes.

## Next build steps after user testing

1. Add explicit authorised roles for changing permanent company/IP/partner criteria.
2. Add a knowledge-management screen to view, amend, supersede and revoke learned rules.
3. Replace keyword case matching with semantic similarity/search.
4. Add deterministic calculator tools for I&E, target DI and SFS percentages.
5. Expose richer structured lead data and existing financial statement fields as assistant tools.
6. Add tests around rule confirmation, permissions and calculation outputs.
