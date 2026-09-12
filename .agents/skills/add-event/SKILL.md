---
name: add-event
description: "Attach or change an Omeka API or view listener in ExeLearning Module.php."
---

# Add an event listener

1. Inspect `Module::attachListeners()` and the closest handler. Consult `omeka-s-api-and-adapters`
   and the supported core event emitter for the precise identifier and payload.
2. Register only the required identifier/event pair. A wildcard layout listener runs for unrelated media;
   return early before loading services if the request/resource is out of scope.
3. Distinguish payloads: hydrate events supply an `entity`; API create/update post events supply a
   `response` whose content is the entity before representation conversion. Do not assume every
   `api.*.post` event has an `entity` parameter. View events expose the renderer as their target.
4. Choose timing deliberately: hydrate precedes flush; create post has a persisted identity; deletion
   cleanup must capture the data it needs before it disappears. Preserve failure handling.
5. Update `ModuleTest::testAttachListenersRegistersEveryOmekaHook` and add behavior tests using the
   real event payload shape. Run `make lint` and `make test-coverage`.
