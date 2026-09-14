# Anchorage Chambers Criteria

## Scope

These rules apply only to Avondale cases intended for Anchorage Chambers.

## Source Separation

The supplied Anchorage Chambers workbook is primarily a case-status / creditor-voting workbook rather than a clean general eligibility-and-packaging sheet. It contains creditor accept/reject expectations, voting percentages, voting houses, fixed-fee indicators and representative-specific comments.

Those voting intentions are deliberately **not** imported into the normal assistant criteria codex. They will be handled by a separate voting-intentions capability so that Jinx does not confuse creditor voting behaviour with partner/IP eligibility rules.

## Non-voting criteria currently established

No complete standalone general-criteria sheet was identified in the supplied Anchorage Chambers workbook comparable with the Assure, Lawson Fox or TIG criteria sheets.

The workbook does contain HMRC and self-employed/fixed-fee statements, but many of those are explicitly phrased as creditor voting outcomes (for example, "will reject") or fee-basis behaviour. Jinx must not silently reinterpret those as general Anchorage Chambers eligibility rules.

Until those voting rules are imported into the separate voting model, the assistant should:

- apply Avondale partner-level rules, including the 65% SFS rule;
- use any later confirmed Anchorage Chambers IP-level rules saved through Jinx's trainable knowledge mechanism;
- state that a specific Anchorage criterion is not yet in the non-voting codex where required rather than inventing one;
- not use creditor voting data from this workbook as if it were a general case criterion.

## Source Handling

Source: supplied Avondale AC Criteria workbook. CASE STATUS CALCULATOR, Master Data, Representatives, Link Financial, HMRC voting statements and Bounce Back Loan voting data were kept out of the general codex for separate voting-intentions work.
