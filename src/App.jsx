import { useState } from "react";
import { RouterProvider } from "react-router-dom";
import router from "./router";
import ErrorBoundary from "./components/ErrorBoundary";
import IntroAnimation from "./components/IntroAnimation";

export default function App() {
  const [showIntro, setShowIntro] = useState(true);

  return (
    <>
      {showIntro && (
        <IntroAnimation onFinish={() => setShowIntro(false)} />
      )}
      <ErrorBoundary>
        <RouterProvider router={router} />
      </ErrorBoundary>
    </>
  );
}
