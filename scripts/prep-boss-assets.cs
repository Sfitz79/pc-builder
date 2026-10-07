using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;

// Prepare Boss-supplied brand + product art for the dark storefront.
//
// 1. Logo and tagline arrive on a WHITE background, not transparent. Left alone
//    they render as a white box on a near-black page. White is keyed out.
// 2. Both carry BLACK lettering. Once the white is gone that black text is
//    invisible on the dark site, so dark pixels are lifted to near-white.
//    Brand red is preserved untouched.
// 3. Product photos are trimmed and re-encoded: the Frostbyte PNG was 1.83 MB
//    for one card.
internal static class PrepAssets
{
    private static bool IsWhite(int r, int g, int b)
    {
        // Near-white AND low saturation, so red/grey antialiasing survives.
        int max = Math.Max(r, Math.Max(g, b));
        int min = Math.Min(r, Math.Min(g, b));
        return r >= 232 && g >= 232 && b >= 232 && (max - min) <= 18;
    }

    private static void KnockOutAndLift(string src, string dst, int maxWidth, bool liftDark)
    {
        using (Bitmap input = new Bitmap(src))
        {
            // Trim fully-transparent-safe borders later; first normalise.
            int w = input.Width, h = input.Height;
            using (Bitmap work = new Bitmap(w, h, PixelFormat.Format32bppArgb))
            {
                using (Graphics g = Graphics.FromImage(work))
                {
                    g.Clear(Color.Transparent);
                    g.DrawImage(input, new Rectangle(0, 0, w, h));
                }

                Rectangle bounds = Rectangle.Empty;
                for (int y = 0; y < h; y++)
                {
                    for (int x = 0; x < w; x++)
                    {
                        Color px = work.GetPixel(x, y);
                        int r = px.R, g2 = px.G, b = px.B;

                        if (IsWhite(r, g2, b))
                        {
                            work.SetPixel(x, y, Color.FromArgb(0, r, g2, b));
                            continue;
                        }

                        // Respect source transparency. Forcing these opaque is what
                        // turned the tagline's already-transparent background into a
                        // black box on the OG card.
                        if (px.A < 8)
                        {
                            work.SetPixel(x, y, Color.FromArgb(0, r, g2, b));
                            continue;
                        }

                        if (liftDark)
                        {
                            double lum = (0.2126 * r + 0.7152 * g2 + 0.0722 * b);
                            if (lum < 96)
                            {
                                // Black lettering -> near-white so it reads on the dark page.
                                int t = (int)Math.Round(Math.Min(1.0, (96 - lum) / 96.0) * 0.92);
                                work.SetPixel(x, y, Color.FromArgb(
                                    px.A,
                                    (int)Math.Min(255, r + (245 - r) * t),
                                    (int)Math.Min(255, g2 + (245 - g2) * t),
                                    (int)Math.Min(255, b + (245 - b) * t)));
                                continue;
                            }
                        }

                        // Keep antialiased edges from keeping a pale white fringe.
                        // Semi-transparent pixels over the knocked-out white are
                        // re-solidified; fully transparent ones were handled above.
                        int a = px.A >= 250 ? 255 : px.A;
                        work.SetPixel(x, y, Color.FromArgb(a, r, g2, b));
                    }
                }

                // Tight-crop to the real content.
                for (int y = 0; y < h; y++)
                {
                    for (int x = 0; x < w; x++)
                    {
                        if (work.GetPixel(x, y).A > 8)
                        {
                            if (bounds.IsEmpty)
                                bounds = new Rectangle(x, y, 1, 1);
                            else
                                bounds = Rectangle.Union(bounds, new Rectangle(x, y, 1, 1));
                        }
                    }
                }
                if (bounds.IsEmpty) bounds = new Rectangle(0, 0, w, h);

                int pad = 6;
                bounds = Rectangle.Inflate(bounds, pad, pad);
                bounds.Intersect(new Rectangle(0, 0, w, h));

                int ow = bounds.Width, oh = bounds.Height;
                if (ow > maxWidth)
                {
                    oh = (int)Math.Round(oh * (maxWidth / (double)ow));
                    ow = maxWidth;
                }

                using (Bitmap cropped = new Bitmap(ow, oh, PixelFormat.Format32bppArgb))
                using (Graphics cg = Graphics.FromImage(cropped))
                {
                    cg.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                    cg.PixelOffsetMode = System.Drawing.Drawing2D.PixelOffsetMode.HighQuality;
                    cg.Clear(Color.Transparent);
                    cg.DrawImage(work, new Rectangle(0, 0, ow, oh), bounds, GraphicsUnit.Pixel);
                    cropped.Save(dst, ImageFormat.Png);
                    Console.Error.WriteLine(dst + "  " + ow + "x" + oh);
                }
            }
        }
    }

    // Product photos: crop to content, cap the long edge, save as progressive JPEG.
    private static void PrepPhoto(string src, string dst, int maxEdge)
    {
        using (Bitmap input = new Bitmap(src))
        {
            int w = input.Width, h = input.Height;
            int ow = w, oh = h;
            if (w > maxEdge || h > maxEdge)
            {
                if (w >= h) { ow = maxEdge; oh = (int)Math.Round(h * (maxEdge / (double)w)); }
                else { oh = maxEdge; ow = (int)Math.Round(w * (maxEdge / (double)h)); }
            }

            using (Bitmap outp = new Bitmap(ow, oh))
            using (Graphics g = Graphics.FromImage(outp))
            {
                g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                g.SmoothingMode = System.Drawing.Drawing2D.SmoothingMode.HighQuality;
                g.PixelOffsetMode = System.Drawing.Drawing2D.PixelOffsetMode.HighQuality;
                g.DrawImage(input, new Rectangle(0, 0, ow, oh));

                // These shots sit on pure black or pure white; that is how the
                // Boss has them and the cards use a matching frame, so the
                // corners are squared off rather than made transparent - a
                // JPEG cannot carry alpha and PNG here would triple the payload.
                ImageCodecInfo codec = null;
                foreach (ImageCodecInfo c in ImageCodecInfo.GetImageEncoders())
                    if (c.FormatID == ImageFormat.Jpeg.Guid) { codec = c; break; }

                using (EncoderParameters ep = new EncoderParameters(1))
                {
                    ep.Param[0] = new EncoderParameter(System.Drawing.Imaging.Encoder.Quality, (long)86);
                    outp.Save(dst, codec, ep);
                }
                Console.Error.WriteLine(dst + "  " + ow + "x" + oh);
            }
        }
    }

    // Crop the robot head out of the full lockup and centre it on a square
    // transparent canvas, so the header chip and the favicon are the same mark
    // rather than a squashed rectangle.
    //
    // The bottom edge is found by SCANNING for the first fully-transparent
    // row band below the top of the mark, not by guessing a fraction of the
    // image height. Guessing was tried first and cut the robot's feet off at
    // 46%, then let a sliver of the "PCTG" lettering through at 52%.
    private static void CropSquareMark(string src, string dst, int size)
    {
        using (Bitmap input = new Bitmap(src))
        {
            int cw = (int)Math.Round(input.Width * 0.74);
            int cx = (input.Width - cw) / 2;

            int top = 0;
            while (top < input.Height - 1 && RowIsBlank(input, top, cx, cw)) top++;
            if (top == 0) top = 1;

            // First blank band more than a third of the way down is the gap
            // between the robot and the PCTG lettering.
            int limit = top + (int)Math.Round(input.Height * 0.20);
            int bottom = input.Height;
            int run = 0;
            for (int y = limit; y < input.Height; y++)
            {
                if (RowIsBlank(input, y, cx, cw))
                {
                    run++;
                    if (run >= 3) { bottom = y - run + 1; break; }
                }
                else run = 0;
            }

            Rectangle crop = new Rectangle(cx, top, cw, Math.Max(1, bottom - top));

            using (Bitmap outp = new Bitmap(size, size, PixelFormat.Format32bppArgb))
            using (Graphics g = Graphics.FromImage(outp))
            {
                g.InterpolationMode = System.Drawing.Drawing2D.InterpolationMode.HighQualityBicubic;
                g.PixelOffsetMode = System.Drawing.Drawing2D.PixelOffsetMode.HighQuality;
                g.Clear(Color.Transparent);

                double scale = Math.Min((double)size / crop.Width, (double)size / crop.Height);
                int dw = (int)Math.Round(crop.Width * scale);
                int dh = (int)Math.Round(crop.Height * scale);
                int dx = (size - dw) / 2;
                int dy = (size - dh) / 2;
                g.DrawImage(input, new Rectangle(dx, dy, dw, dh), crop, GraphicsUnit.Pixel);

                outp.Save(dst, ImageFormat.Png);
                Console.Error.WriteLine(dst + "  " + size + "x" + size + "  from " + crop);
            }
        }
    }

    private static bool RowIsBlank(Bitmap bmp, int y, int x0, int width)
    {
        if (y < 0 || y >= bmp.Height) return true;
        int opaque = 0;
        for (int x = x0; x < x0 + width && x < bmp.Width; x++)
        {
            if (bmp.GetPixel(x, y).A > 16) opaque++;
        }
        return opaque == 0;
    }

    private static int Main(string[] args)
    {
        string root = args.Length > 0 ? args[0] : "public/img";
        string brand = Path.Combine(root, "brand");
        string hero = Path.Combine(root, "hero");
        string systems = Path.Combine(root, "systems");

        KnockOutAndLift(Path.Combine(brand, "pctg-logo-src.png"), Path.Combine(brand, "pctg-logo.png"), 720, true);
        KnockOutAndLift(Path.Combine(brand, "pctg-tagline-src.png"), Path.Combine(brand, "pctg-tagline.png"), 720, false);
        CropSquareMark(Path.Combine(brand, "pctg-logo.png"), Path.Combine(brand, "pctg-mark-512.png"), 512);
        CropSquareMark(Path.Combine(brand, "pctg-logo.png"), Path.Combine(brand, "pctg-mark-180.png"), 180);

        PrepPhoto(Path.Combine(systems, "arctic-ghost-src.jpg"), Path.Combine(systems, "arctic-ghost.jpg"), 1200);
        PrepPhoto(Path.Combine(systems, "frostbyte-xt-src.png"), Path.Combine(systems, "frostbyte-xt.jpg"), 1200);
        PrepPhoto(Path.Combine(systems, "stormbyte-src.png"), Path.Combine(systems, "stormbyte.jpg"), 1200);

        Console.Error.WriteLine("done");
        return 0;
    }
}
