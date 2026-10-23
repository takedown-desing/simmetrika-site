// Служебный файл сборки: карта адресов записей и переадресации. После сборки scripts/save-cms-state.mjs
// сохраняет его в src/cms/settings, создаёт страницы-переадресации и удаляет из dist.
import { urlMap, redirectList } from '../lib/cms.mjs';
export function GET() {
  const { auto, resolved } = redirectList();
  return new Response(JSON.stringify({ urlMap: urlMap(), redirectsAuto: auto, resolved }));
}
