import { lazy, Suspense } from "react";
import { Outlet, createHashRouter } from "react-router-dom";
import Navbar from "./components/Navbar";
import Footer from "./components/Footer";
import ChatBot from "./components/ChatBot";
import Home from "./pages/Home";

const Builder = lazy(() => import("./pages/Builder"));
const AdminBuilder = lazy(() => import("./pages/AdminBuilder"));
const Summary = lazy(() => import("./pages/Summary"));
const BottleneckCalculator = lazy(() => import("./pages/BottleneckCalculator"));
const ComponentComparison = lazy(() => import("./pages/ComponentComparison"));
const FpsCalculator = lazy(() => import("./pages/FpsCalculator"));
const BuildHistory = lazy(() => import("./pages/BuildHistory"));
const AIGenerator = lazy(() => import("./pages/AIGenerator"));
const GameSystemGenerator = lazy(() => import("./pages/GameSystemGenerator"));

function Loading() {
  return <div style={{ textAlign: 'center', padding: '40px', color: '#666' }}>Loading...</div>;
}

function RootLayout() {
  return (
    <>
      <Navbar />
      <main className="container">
        <Suspense fallback={<Loading />}>
          <Outlet />
        </Suspense>
      </main>
      <Footer />
      <ChatBot />
    </>
  );
}

const router = createHashRouter([
  {
    path: "/",
    element: <RootLayout />,
    children: [
      { index: true, element: <Home /> },
      { path: "builder", element: <Builder /> },
      { path: "summary", element: <Summary /> },
      { path: "admin", element: <AdminBuilder /> },
      { path: "bottleneck-calculator", element: <BottleneckCalculator /> },
      { path: "compare", element: <ComponentComparison /> },
      { path: "fps-calculator", element: <FpsCalculator /> },
      { path: "builds", element: <BuildHistory /> },
      { path: "ai-generator", element: <AIGenerator /> },
      { path: "game-system-generator", element: <GameSystemGenerator /> },
    ]
  }
]);

export default router;
