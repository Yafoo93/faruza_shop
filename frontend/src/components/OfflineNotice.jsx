import React, { useEffect, useState } from "react";

function OfflineNotice() {
  const [isOnline, setIsOnline] = useState(navigator.onLine);

  useEffect(() => {
    function updateOnlineStatus() {
      setIsOnline(navigator.onLine);
    }

    window.addEventListener("online", updateOnlineStatus);
    window.addEventListener("offline", updateOnlineStatus);

    return () => {
      window.removeEventListener("online", updateOnlineStatus);
      window.removeEventListener("offline", updateOnlineStatus);
    };
  }, []);

  if (isOnline) return null;

  return (
    <section className="offline-strip">
      Offline mode: the app shell is cached, but checkout still needs the local Laravel API running.
    </section>
  );
}

export default OfflineNotice;
