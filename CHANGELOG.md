# Release Notes for Telegram Bridge plugin

## Unreleased

- Fixed a php error which occurred where specified GRAPHQL_QUERY_FIELD was not available in the entry layout.
- Fixed a bug where translation for order status were returned by the plugin translation file not the project translation file.
- Fixed the result table for the Top Product Types tool where the header displayed 'Customer' instead of 'Name'.

## 0.3.1 - 2024-03-04

- Fixed a bug where $ALLOWED_TELEGRAM_CHAT_IDS_USER environment setting was not validated correctly.

## 0.3.0 - 2024-03-01

- Telegram chats can now execute GraphQL queries if the Craft version is 4.8.0 or higher.
- Improved error message when the GraphQL API page is not found.
- Improved chat messages when environment settings or user preferences are changed.
- Fixed a bug where the plugin's environment settings were not validated correctly.
- Fixed a bug where environment settings related to GraphQL were validated, even in Craft versions without GraphQL support.

## 0.2.0 - 2024-02-22

- The 'not' and 'all' button in GQL queries chats are now only shown when there are at least two items.
- Pressing 'all' button in GQL queries chats now advances chats to the next step when it is appropriate.
- Fixed a bug where executing GQL queries without variables failed to return the expected results.
- Fixed a bug in executing GQL queries where static arguments values for section, sectionId, volume and volumeId were ignored for suggesting type, typeId and folderId buttons.
- Fixed a bug in executing GQL queries where static argument value for the limit was ignored when calculating the offset.
- Fixed a bug where suggested entry types for multiple selected sections were incorrect in GQL chats.

## 0.1.2 - 2024-02-18

- Fixed a bug where using old keyboard buttons in chat resulted in unexpected behavior.

## 0.1.1 - 2024-02-17

- Fixed a bug where the Recent Entries tool failed to return the expected results.

## 0.1.0 - 2024-02-16

- Initial Release.