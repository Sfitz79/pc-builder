using System;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.Drawing.Imaging;
using System.IO;

// Prepare the PCTG Business sub-brand assets.
//
// The Boss supplied five images totalling 8.1 MB. Three of them are logo or
// lockup art on a WHITE background that would render as a white box on the dark
// site, and all five are far too heavy for web delivery at 1024-1536px PNG.
//
// The same white knock-out and dark-pixel lift used for the main PCTG logo is
// reused here, with one important difference: the chrome/silver lettering in
// this mark sits in the MIDTONES, so lifting only the very darkest pixels
// leaves "PCTG" readable but "PCTECHGUY BUSINESS" invisible on a dark page.
// LiftDark here is therefore wider and targets near-black AND dark grey, while
// deliberately preserving the blue accents so the sub-brand stays blue.
//
// Reused from scripts/prep-boss-assets.cs rather than duplicated.
internal static class PrepBusiness
{
    private static bool IsWhite(int r, int g, int b)
    {
        int max = Math.Max(r, Math.Max(g, b));
        int min = Math.Min(r, Math.Min(g, b));
        // satTol is 34 rather than 20 because the chrome/silver lettering in
        // this mark is very faintly blue, so at 20 it read as saturated and was
        // not keyed - leaving a visible pale halo behind the "PCTG | Workstation
        // Series" lockup. 34 clears the chrome and still leaves the genuine
        // blue accent, whose channels differ by far more than that.
        return r >= 228 && g >= 228 && b >= 228 && (max - min) <= 34;
    }

    // liftTo: luminance below which pixels are lifted toward near-white.
    // The blue accents sit around luminance 90-140, so liftTo must stay above
    // that or the sub-brand turns monochrome white.
    private static void KnockOutAndLift(string src, string dst, int maxWidth, double liftTo)
    {
        using (Bitmap input = new Bitmap(src))
        {
            int w = input.Width, h = input.Height;
            using (Bitmap work = new Bitmap(w, h, PixelFormat.Format32bppArgb))
            {
                using (Graphics g = Graphics.FromImage(work))
                {
                    g.Clear(Color.Transparent);
                    g.DrawImage(input, new Rectangle(0, 0, w, h));
                }

                for (int y = 0; y < h; y++)
                {
                    for (int x = 0; x < w; x++)
                    {
                        Color px = work.GetPixel(x, y);
                        int r = px.R, gg = px.G, b = px.B;

                        if (IsWhite(r, gg, b)) { work.SetPixel(x, y, Color.FromArgb(0, r, gg, b)); continue; }
                        if (px.A < 8) { work.SetPixel(x, y, Color.FromArgb(0, r, gg, b)); continue; }

                        double lum = 0.2126 * r + 0.7152 * gg + 0.0722 * b;
                        if (lum < liftTo)
                        {
                            double t = Math.Min(1.0, (liftTo - lum) / liftTo);
                            int nr = (int)Math.Min(255, r + (245 - r) * t);
                            int ng = (int)Math.Min(255, gg + (245 - gg) * t);
                            int nb = (int)Math.Min(255, b + (245 - b) * t);
                            work.SetPixel(x, y, Color.FromArgb(px.A, nr, ng, nb));
                            continue;
                        }

                        work.SetPixel(x, y, Color.FromArgb(px.A >= 250 ? 255 : px.A, r, gg, b));
                    }
                }

                Rectangle bounds = Rectangle.Empty;
                for (int y = 0; y < h; y++)
                    for (int x = 0; x < w; x++)
                        if (work.GetPixel(x, y).A > 8)
                            bounds = bounds.IsEmpty ? new Rectangle(x, y, 1, 1) : Rectangle.Union(bounds, new Rectangle(x, y, 1, 1));
                if (bounds.IsEmpty) bounds = new Rectangle(0, 0, w, h);

                bounds = Rectangle.Intersect(Rectangle.Inflate(bounds, 6, 6), new Rectangle(0, 0, w, h));

                int ow = bounds.Width, oh = bounds.Height;
                if (ow > maxWidth) { oh = (int)Math.Round(oh * (maxWidth / (double)ow)); ow = maxWidth; }

                using (Bitmap cropped = new Bitmap(ow, oh, PixelFormat.Format32bppArgb))
                using (Graphics cg = Graphics.FromImage(cropped))
                {
                    cg.InterpolationMode = InterpolationMode.HighQualityBicubic;
                    cg.PixelOffsetMode = PixelOffsetMode.HighQuality;
                    cg.Clear(Color.Transparent);
                    cg.DrawImage(work, new Rectangle(0, 0, ow, oh), bounds, GraphicsUnit.Pixel);
                    cropped.Save(dst, ImageFormat.Png);
                    Console.Error.WriteLine(Path.GetFileName(dst) + "  " + ow + "x" + oh);
                }
            }
        }
    }

    // Crop the tower mark out of the lockup by scanning for the first blank
    // row band, the same approach used for the main PCTG mark.
    private static void CropSquareMark(string src, string dst, int size)
    {
        using (Bitmap input = new Bitmap(src))
        {
            int cw = (int)Math.Round(input.Width * 0.74);
            int cx = (input.Width - cw) / 2;

            int top = 0;
            while (top < input.Height - 1 && RowBlank(input, top, cx, cw)) top++;
            if (top == 0) top = 1;

            int limit = top + (int)Math.Round(input.Height * 0.14);
            int bottom = input.Height, run = 0;
            for (int y = limit; y < input.Height; y++)
            {
                if (RowBlank(input, y, cx, cw)) { run++; if (run >= 3) { bottom = y - run + 1; break; } }
                else run = 0;
            }

            Rectangle crop = new Rectangle(cx, top, cw, Math.Max(1, bottom - top));
            using (Bitmap outp = new Bitmap(size, size, PixelFormat.Format32bppArgb))
            using (Graphics g = Graphics.FromImage(outp))
            {
                g.InterpolationMode = InterpolationMode.HighQualityBicubic;
                g.Clear(Color.Transparent);
                double s = Math.Min((double)size / crop.Width, (double)size / crop.Height);
                int dw = (int)Math.Round(crop.Width * s), dh = (int)Math.Round(crop.Height * s);
                g.DrawImage(input, new Rectangle((size - dw) / 2, (size - dh) / 2, dw, dh), crop, GraphicsUnit.Pixel);
                outp.Save(dst, ImageFormat.Png);
                Console.Error.WriteLine(Path.GetFileName(dst) + "  " + size + "x" + size);
            }
        }
    }

    private static bool RowBlank(Bitmap bmp, int y, int x0, int width)
    {
        if (y < 0 || y >= bmp.Height) return true;
        int opaque = 0;
        for (int x = x0; x < x0 + width && x < bmp.Width; x++)
            if (bmp.GetPixel(x, y).A > 16) opaque++;
        return opaque == 0;
    }

    private static ImageCodecInfo Jpeg()
    {
        foreach (ImageCodecInfo c in ImageCodecInfo.GetImageEncoders())
            if (c.FormatID == ImageFormat.Jpeg.Guid) return c;
        return null;
    }

    // Product/hero photography: cap the long edge and re-encode as JPEG.
    private static void PrepPhoto(string src, string dst, int maxEdge, long q)
    {
        using (Bitmap input = new Bitmap(src))
        {
            int w = input.Width, h = input.Height, ow = w, oh = h;
            if (w > maxEdge || h > maxEdge)
            {
                if (w >= h) { ow = maxEdge; oh = (int)Math.Round(h * (maxEdge / (double)w)); }
                else { oh = maxEdge; ow = (int)Math.Round(w * (maxEdge / (double)h)); }
            }

            using (Bitmap outp = new Bitmap(ow, oh))
            using (Graphics g = Graphics.FromImage(outp))
            {
                g.InterpolationMode = InterpolationMode.HighQualityBicubic;
                g.SmoothingMode = SmoothingMode.HighQuality;
                g.PixelOffsetMode = PixelOffsetMode.HighQuality;
                g.DrawImage(input, new Rectangle(0, 0, ow, oh));
                using (EncoderParameters ep = new EncoderParameters(1))
                {
                    ep.Param[0] = new EncoderParameter(System.Drawing.Imaging.Encoder.Quality, q);
                    outp.Save(dst, Jpeg(), ep);
                }
                Console.Error.WriteLine(Path.GetFileName(dst) + "  " + ow + "x" + oh);
            }
        }
    }

    private static int Main(string[] args)
    {
        string dir = args.Length > 0 ? args[0] : "public\\img\\business";
        Directory.CreateDirectory(dir);

        // lockTo is deliberately wider than the main logo's 96: this mark's
        // sub-line sits in dark grey and would otherwise vanish on the dark page.
        KnockOutAndLift(Path.Combine(dir, "logo-src.png"), Path.Combine(dir, "logo.png"), 640, 150);
        KnockOutAndLift(Path.Combine(dir, "workstation-series-src.png"), Path.Combine(dir, "workstation-series.png"), 640, 150);
        CropSquareMark(Path.Combine(dir, "logo.png"), Path.Combine(dir, "mark-512.png"), 512);
        CropSquareMark(Path.Combine(dir, "logo.png"), Path.Combine(dir, "mark-180.png"), 180);

        // Already on a dark background with its alpha intact - only downscale.
        PrepPhoto(Path.Combine(dir, "logo-dark-src.png"), Path.Combine(dir, "logo-dark.png"), 640, 90);

        PrepPhoto(Path.Combine(dir, "server-xeon-src.png"), Path.Combine(dir, "server-xeon.jpg"), 1100, 84);
        PrepPhoto(Path.Combine(dir, "enterprise-src.png"), Path.Combine(dir, "enterprise.jpg"), 1500, 84);

        Console.Error.WriteLine("done");
        return 0;
    }
}