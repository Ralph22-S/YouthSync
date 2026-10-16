import { createContext, useContext, useEffect } from 'react';

export const StoreContext = createContext(null);
export const useStore = () => useContext(StoreContext);

/**
 * Asks the SK store for whole collections this screen reads for pickers,
 * detail pages or cross-links ('youth', 'programs', 'registrations',
 * 'assistance', 'applications', 'users', 'types'). Nothing is loaded at sign-in:
 * a collection is fetched the first time a screen asks for it and is then kept
 * fresh after saves. Returns true once all of them have loaded. Stores without
 * lazy collections (admin, youth portal) report ready straight away.
 */
export function useStoreData(...keys) {
  const store = useContext(StoreContext);
  const wanted = keys.filter(Boolean).join(',');
  const ensure = store?.ensureData;
  useEffect(() => {
    if (ensure && wanted) ensure(wanted.split(','));
  }, [ensure, wanted]);
  if (!wanted || typeof store?.dataReady !== 'function') return true;
  return store.dataReady(wanted.split(','));
}
